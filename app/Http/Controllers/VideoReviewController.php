<?php

namespace App\Http\Controllers;

use App\Models\ReviewComment;
use App\Models\ReviewVideo;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Notifications\VideoReviewUpdate;
use App\Services\Ai\VideoCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Video review inside the portal.
 *
 * The editor uploads a cut to its editing card (in pieces, so a big file or
 * a dropped signal does not start over). Gemini checks it after the upload
 * answers. A reviewer -- anyone who manages that editor, admins always --
 * watches it, pins comments to moments, and either approves it or sends it
 * back, when each open comment becomes a correction on the card. A new
 * upload is the next version.
 */
class VideoReviewController extends Controller
{
    /** Each piece of an upload. Small enough for any proxy, big enough to be quick. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public const MAX_BYTES = 1536 * 1024 * 1024;

    public const TYPES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'];

    /** Seconds of AI-check steps one web request starts after answering. */
    private const CHECK_SLICE = 20;

    // ——— The queue and the page ———

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->managesAnyone(), 403);

        $latest = ReviewVideo::query()
            ->whereIn('id', ReviewVideo::query()->selectRaw('MAX(id)')->groupBy('todo_id'))
            ->with(['todo.user:id,name,avatar_path', 'todo.contentItem:id,venture', 'uploadedBy:id,name'])
            ->withCount(['comments as open_comments' => fn ($q) => $q->whereNull('resolved_at')])
            ->latest('id')->limit(200)->get()
            ->filter(fn (ReviewVideo $v) => $v->todo && $v->reviewableBy($user));

        // The month calendar: each video once a day -- its latest version that
        // day, carrying the versions uploaded that day (v1 → v2 → v3).
        $month = rescue(fn () => \Illuminate\Support\Carbon::createFromFormat('Y-m', (string) $request->query('month', now()->format('Y-m')))->startOfMonth(), now()->startOfMonth(), false);
        $monthVideos = ReviewVideo::query()
            ->whereBetween('created_at', [$month, $month->copy()->endOfMonth()])
            ->with(['todo.user:id,name,avatar_path', 'todo.contentItem:id,venture', 'uploadedBy:id,name'])
            ->withCount(['comments as open_comments' => fn ($q) => $q->whereNull('resolved_at')])
            ->orderBy('created_at')->get()
            ->filter(fn (ReviewVideo $v) => $v->todo && $v->reviewableBy($user))
            ->groupBy(fn (ReviewVideo $v) => $v->created_at->toDateString())
            ->map(fn ($day) => $day->groupBy('todo_id')->map(function ($versions) {
                $latest = $versions->sortBy('version')->last();
                $latest->setAttribute('day_versions', $versions->pluck('version')->sort()->values()->all());

                return $latest;
            })->sortByDesc('id')->values());

        return view('reviews.index', [
            'view' => $request->query('view') === 'calendar' ? 'calendar' : 'list',
            'month' => $month,
            'monthVideos' => $monthVideos,
            'settings' => \App\Models\ReviewSetting::current(),
            'staff' => $user->isAdmin() ? User::query()->where('role', '!=', User::ROLE_CLIENT)->orderBy('name')->get(['id', 'name']) : collect(),
            'waiting' => $latest->where('status', ReviewVideo::PENDING)->sortBy('id')->values(),
            'done' => $latest->where('status', '!=', ReviewVideo::PENDING)->take(30)->values(),
        ]);
    }

    public function show(Request $request, ReviewVideo $video): View
    {
        abort_unless($video->visibleTo($request->user()), 404);

        $video->load(['todo.user:id,name,avatar_path', 'todo.contentItem', 'uploadedBy:id,name', 'reviewedBy:id,name']);

        return view('reviews.show', [
            'video' => $video,
            'versions' => ReviewVideo::query()->where('todo_id', $video->todo_id)->orderByDesc('version')->get(['id', 'version', 'status', 'created_at', 'deleted_file_at']),
            'state' => $this->state($video, $request->user()),
        ]);
    }

    /** Polled by the page: comments, the AI report, the verdict. */
    public function live(Request $request, ReviewVideo $video): JsonResponse
    {
        abort_unless($video->visibleTo($request->user()), 404);

        // Where this person is in the cut, so the others see them watching.
        if ($request->has('t')) {
            DB::table('review_presence')->upsert([[
                'review_video_id' => $video->id,
                'user_id' => $request->user()->id,
                'at_seconds' => max(0, min(36000, (float) $request->query('t'))),
                'playing' => $request->boolean('p'),
                'seen_at' => now(),
            ]], ['review_video_id', 'user_id'], ['at_seconds', 'playing', 'seen_at']);
        }

        // While the page is open it moves the AI check on, a slice after each
        // answer, so the check never hangs on the every-minute job alone.
        $video = $video->fresh();
        if (VideoCheck::wantsWork($video)) {
            defer(fn () => VideoCheck::run($video, self::CHECK_SLICE));
        }

        return response()->json($this->state($video, $request->user()));
    }

    /** The file, with byte ranges so the player can seek. */
    public function file(Request $request, ReviewVideo $video): BinaryFileResponse
    {
        abort_unless($video->visibleTo($request->user()) && $video->hasFile(), 404);

        return response()->file($video->filePath(), [
            'Content-Type' => $video->mime ?: 'video/mp4',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function poster(Request $request, ReviewVideo $video): BinaryFileResponse
    {
        abort_unless($video->visibleTo($request->user()) && $video->poster_path && is_file(storage_path('app/private/'.$video->poster_path)), 404);

        return response()->file(storage_path('app/private/'.$video->poster_path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800']);
    }

    // ——— Uploading a version ———

    public function start(Request $request, Todo $todo): JsonResponse
    {
        $card = $todo->parent ?? $todo;
        $this->authoriseUpload($card, $request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BYTES],
            'type' => ['nullable', 'string', 'max:60'],
        ]);
        $type = $this->typeOf($data['type'] ?? null, $data['name']);
        if (! $type) {
            return response()->json(['message' => 'Upload an MP4 (best), MOV or WebM video.'], 422);
        }

        $id = (string) Str::uuid();
        File::ensureDirectoryExists($this->tmp());
        File::put($this->tmp($id.'.json'), json_encode([
            'todo_id' => $card->id, 'user_id' => $request->user()->id,
            'name' => $data['name'], 'size' => (int) $data['size'], 'type' => $type, 'at' => now()->timestamp,
        ]));
        File::put($this->tmp($id.'.part'), '');

        return response()->json(['upload' => $id, 'chunk' => self::CHUNK_BYTES]);
    }

    /** One piece, at its offset. Sending the same piece twice is harmless. */
    public function chunk(Request $request, string $upload): JsonResponse
    {
        [$meta, $part] = $this->upload($upload, $request->user());
        $offset = (int) $request->query('offset', 0);
        clearstatcache(true, $part);
        $have = filesize($part);

        if ($offset > $have) {
            return response()->json(['message' => 'A piece is missing. The upload will resume.', 'received' => $have], 409);
        }

        if ($offset === $have) {
            $in = $request->getContent(true);
            $out = fopen($part, 'ab');
            stream_copy_to_stream($in, $out, self::CHUNK_BYTES + 1);
            fclose($out);
            clearstatcache(true, $part);
            $have = filesize($part);
        }

        if ($have > $meta['size']) {
            @unlink($part);

            return response()->json(['message' => 'The file came in bigger than it said. Start again.'], 422);
        }

        return response()->json(['received' => $have]);
    }

    public function finish(Request $request, string $upload): JsonResponse
    {
        [$meta, $part] = $this->upload($upload, $request->user());
        $data = $request->validate([
            'duration' => ['nullable', 'numeric', 'min:0', 'max:36000'],
            'width' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'poster' => ['nullable', 'string', 'max:3000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        clearstatcache(true, $part);
        if (filesize($part) !== $meta['size']) {
            return response()->json(['message' => 'The upload is not complete yet.', 'received' => filesize($part)], 409);
        }

        $card = Todo::query()->findOrFail($meta['todo_id']);
        $user = $request->user();

        $video = DB::transaction(function () use ($card, $user, $meta, $part, $data, $upload) {
            $version = (int) ReviewVideo::query()->where('todo_id', $card->id)->lockForUpdate()->max('version') + 1;
            $ext = ['video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v'][$meta['type']] ?? 'mp4';
            $dir = 'reviews/'.$card->id;
            File::ensureDirectoryExists(storage_path('app/private/'.$dir));
            $path = $dir.'/v'.$version.'-'.Str::random(10).'.'.$ext;
            File::move($part, storage_path('app/private/'.$path));

            $poster = null;
            if (! empty($data['poster']) && preg_match('#^data:image/jpeg;base64,(.+)$#', $data['poster'], $m) && ($bytes = base64_decode($m[1], true)) && @imagecreatefromstring($bytes)) {
                $poster = $dir.'/v'.$version.'-poster.jpg';
                File::put(storage_path('app/private/'.$poster), $bytes);
            }

            $video = new ReviewVideo;
            $video->forceFill([
                'todo_id' => $card->id,
                'version' => $version,
                'uploaded_by_id' => $user->id,
                'original_name' => Str::limit($meta['name'], 250, ''),
                'path' => $path,
                'poster_path' => $poster,
                'mime' => $meta['type'],
                'size' => $meta['size'],
                'duration' => $data['duration'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'status' => ReviewVideo::PENDING,
                // Only the first cut gets the AI check; later versions are checked
                // against the changes asked on the one before.
                'ai_status' => $version === 1 && config('ai.review.enabled', true) ? 'queued' : 'none',
            ])->save();

            if (filled($data['note'] ?? null)) {
                ReviewComment::create(['review_video_id' => $video->id, 'user_id' => $user->id, 'body' => 'Editor: '.trim($data['note'])]);
            }

            $this->sendForReview($card, $video, $user);

            return $video;
        });
        @unlink($this->tmp($upload.'.json'));

        $this->tell($this->reviewersOf($card), $video, 'Video '.($video->version > 1 ? 'v'.$video->version.' ' : '').'to review: '.$card->title,
            $user->name.' uploaded '.($video->version > 1 ? 'version '.$video->version : 'a cut').($card->venture ? ' for '.$card->venture : '').'.');

        // The AI check starts after this answer, a step at a time; the page's
        // polling and reviews:housekeeping carry it on from where it got to.
        if (VideoCheck::wantsWork($video)) {
            defer(fn () => VideoCheck::run($video, self::CHECK_SLICE));
        }

        return response()->json(['video' => $video->summary(), 'url' => route('reviews.show', $video)], 201);
    }

    // ——— Commenting ———

    public function comment(Request $request, ReviewVideo $video): JsonResponse
    {
        $user = $request->user();
        abort_unless($video->visibleTo($user), 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'at_seconds' => ['nullable', 'numeric', 'min:0', 'max:36000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = ReviewComment::query()->where('review_video_id', $video->id)->whereNull('parent_id')->findOrFail($data['parent_id']);
            $data['at_seconds'] = null; // a reply belongs to its comment's moment
        }

        // The same words on the same moment is one comment: a double tap, or
        // an AI flag somebody already added.
        $at = $data['at_seconds'] ?? null;
        $twin = ReviewComment::query()->where('review_video_id', $video->id)->where('parent_id', $parent?->id)
            ->where('body', trim($data['body']))
            ->when($at === null, fn ($q) => $q->whereNull('at_seconds'), fn ($q) => $q->whereBetween('at_seconds', [(float) $at - 0.05, (float) $at + 0.05]))
            ->exists();
        if (! $twin) {
            $comment = ReviewComment::create([
                'review_video_id' => $video->id,
                'parent_id' => $parent?->id,
                'user_id' => $user->id,
                'at_seconds' => $data['at_seconds'] ?? null,
                'body' => trim($data['body']),
            ]);

            // Already sent back, and the editor has not uploaded the fix yet:
            // a reviewer's new comment is one more change, not a note nobody acts on.
            if (! $parent && $video->status === ReviewVideo::CHANGES && $video->reviewableBy($user) && $this->isLatest($video)) {
                $this->asCorrection($video, $comment, $user);
            }

            // A reply reaches the person who wrote the comment; a comment reaches the editor.
            $to = $parent ? collect([$parent->user]) : collect([$video->todo?->user]);
            $to = $to->filter()->reject(fn (User $u) => $u->id === $user->id);
            if ($to->isNotEmpty()) {
                $this->tell($to, $video, ($parent ? 'Reply from ' : 'Comment from ').$user->name.' · '.$video->todo?->title,
                    Str::limit(($parent || empty($data['at_seconds']) ? '' : ReviewComment::clock((float) $data['at_seconds']).' — ').trim($data['body']), 180));
            }
        }

        return response()->json($this->state($video->fresh(), $user), 201);
    }

    /** Fix your own comment's words (not once it has become a correction). */
    public function edit(Request $request, ReviewComment $comment): JsonResponse
    {
        $video = $comment->video;
        abort_unless($video->visibleTo($request->user()) && $comment->user_id === $request->user()->id, 403);
        abort_if($comment->correction_todo_id !== null, 422, 'This comment is already a correction on the card.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'at_seconds' => ['nullable', 'numeric', 'min:0', 'max:36000'],
        ]);
        $comment->forceFill(['body' => trim($data['body'])] + ($comment->parent_id === null && $request->has('at_seconds') ? ['at_seconds' => $data['at_seconds']] : []))->save();

        return response()->json($this->state($video->fresh(), $request->user()));
    }

    /**
     * On a newer version: was this change from the last one really fixed?
     * "not_fixed" raises it again as a comment on the newer version, at the
     * same moment, so it goes back to the editor with the rest.
     */
    public function checkFix(Request $request, ReviewComment $comment): JsonResponse
    {
        $user = $request->user();
        $old = $comment->video;
        $data = $request->validate([
            'state' => ['required', \Illuminate\Validation\Rule::in(['confirmed', 'not_fixed', 'clear'])],
            'on' => ['required', 'integer'],
        ]);
        $current = ReviewVideo::query()->where('todo_id', $old->todo_id)->where('version', '>', $old->version)->findOrFail($data['on']);
        abort_unless($current->reviewableBy($user) && $comment->correction_todo_id !== null, 403);

        if ($data['state'] === 'clear') {
            $comment->forceFill(['fix_state' => null, 'fix_checked_by_id' => null, 'fix_checked_at' => null])->save();
        } else {
            $comment->forceFill(['fix_state' => $data['state'], 'fix_checked_by_id' => $user->id, 'fix_checked_at' => now()])->save();
        }

        if ($data['state'] === 'not_fixed') {
            $body = 'Still not fixed: '.Str::after($comment->body, 'Still not fixed: ');
            $exists = ReviewComment::query()->where('review_video_id', $current->id)->whereNull('parent_id')->where('body', $body)->exists();
            if (! $exists) {
                $again = ReviewComment::create(['review_video_id' => $current->id, 'user_id' => $user->id, 'at_seconds' => $comment->at_seconds, 'body' => $body]);
                if ($current->status === ReviewVideo::CHANGES && $this->isLatest($current)) {
                    $this->asCorrection($current, $again, $user);
                }
            }
        }

        return response()->json($this->state($current->fresh(), $user));
    }

    /** Agree with a comment (again to take it back). */
    public function like(Request $request, ReviewComment $comment): JsonResponse
    {
        $video = $comment->video;
        abort_unless($video->visibleTo($request->user()), 404);

        $comment->likers()->toggle([$request->user()->id => ['created_at' => now()]]);

        return response()->json($this->state($video->fresh(), $request->user()));
    }

    public function resolve(Request $request, ReviewComment $comment): JsonResponse
    {
        $video = $comment->video;
        abort_unless($video->visibleTo($request->user()), 404);

        $comment->forceFill($request->boolean('resolved', true)
            ? ['resolved_at' => now(), 'resolved_by_id' => $request->user()->id]
            : ['resolved_at' => null, 'resolved_by_id' => null])->save();

        return response()->json($this->state($video->fresh(), $request->user()));
    }

    public function forget(Request $request, ReviewComment $comment): JsonResponse
    {
        $video = $comment->video;
        abort_unless($comment->user_id === $request->user()->id || $video->reviewableBy($request->user()), 403);
        abort_if($comment->correction_todo_id !== null, 422, 'This comment is already a correction on the card.');
        $comment->delete(); // replies and likes go with it

        return response()->json($this->state($video->fresh(), $request->user()));
    }

    // ——— The verdict ———

    /**
     * My OK on this version. The cut is approved once every required
     * approver (Review settings) has given theirs -- or, with none set, on
     * the first OK. Until then the others are asked.
     *
     * for_all: an approver (or an admin) approves for everyone still to --
     * the usual case when the others are away. Each of those OKs records who
     * gave it, and they are told.
     */
    public function approve(Request $request, ReviewVideo $video): JsonResponse
    {
        $user = $request->user();
        abort_unless($video->reviewableBy($user), 403);
        $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'for_all' => ['nullable', 'boolean']]);
        $note = trim((string) $request->input('note', '')) ?: null;
        $forAll = $request->boolean('for_all');
        if ($forAll && ! $this->canApproveForAll($user)) {
            return response()->json(['message' => 'Only an approver can approve for everyone.'], 403);
        }

        $card = $video->todo;
        if ($video->status !== ReviewVideo::PENDING) {
            return response()->json(['message' => 'This version has already been decided.'], 422);
        }
        if ($card->status !== Todo::STATUS_COMPLETED) {
            return response()->json(['message' => 'The card is not finished: the editor has more to do on it.'], 422);
        }

        \App\Models\ReviewApproval::query()->updateOrCreate(
            ['review_video_id' => $video->id, 'user_id' => $user->id],
            ['by_user_id' => $user->id, 'note' => $note, 'created_at' => now()],
        );

        $covered = collect();
        if ($forAll) {
            foreach (collect($video->signOff()['required'])->where('approved', false) as $other) {
                \App\Models\ReviewApproval::query()->create([
                    'review_video_id' => $video->id, 'user_id' => $other['id'], 'by_user_id' => $user->id,
                    'note' => 'Approved by '.$user->name.' for everyone', 'created_at' => now(),
                ]);
                $covered->push($other['id']);
            }
        }

        $signOff = $video->signOff();

        if ($signOff['complete']) {
            $names = $covered->isNotEmpty() ? Str::before($user->name, ' ').' (for everyone)' : collect($signOff['required'])->pluck('name')->map(fn ($n) => Str::before($n, ' '))
                ->whenEmpty(fn ($c) => $c->push(Str::before($user->name, ' ')))->implode(' and ');
            $video->forceFill(['status' => ReviewVideo::APPROVED, 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $card->review(Todo::REVIEW_APPROVED, $user, 'Approved v'.$video->version.' by '.$names.($note ? ': '.$note : ''));

            $this->tell(collect([$card->user])->filter(), $video, 'Approved: '.$card->title,
                'Approved by '.$names.'. You can make it Video ready with its publishing time now.');
            if ($covered->isNotEmpty()) {
                $this->tell(User::query()->whereIn('id', $covered)->get(), $video, 'Approved for you: '.$card->title,
                    Str::before($user->name, ' ').' approved version '.$video->version.' for everyone, your OK included.');
            }
        } else {
            $waiting = collect($signOff['required'])->where('approved', false);
            $this->tell(User::query()->whereIn('id', $waiting->pluck('id'))->get(), $video, 'Your OK needed: '.$card->title,
                Str::before($user->name, ' ').' approved version '.$video->version.'. It is approved when you approve too.');
        }

        return response()->json($this->state($video->fresh(), $user));
    }

    /** An approver on the list, or an admin, may approve for everyone. */
    private function canApproveForAll(User $user): bool
    {
        return $user->isAdmin() || in_array($user->id, array_map('intval', \App\Models\ReviewSetting::current()->approver_ids ?? []), true);
    }

    /** Take my OK back, while the cut is still waiting. */
    public function unapprove(Request $request, ReviewVideo $video): JsonResponse
    {
        $user = $request->user();
        abort_unless($video->reviewableBy($user), 403);
        abort_unless($video->status === ReviewVideo::PENDING, 422, 'This version has already been decided.');

        \App\Models\ReviewApproval::query()->where('review_video_id', $video->id)->where('user_id', $user->id)->delete();

        return response()->json($this->state($video->fresh(), $user));
    }

    /** Who must approve, and whether Video ready waits for it. Admins only. */
    public function settings(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'approver_ids' => ['nullable', 'array', 'max:10'],
            'approver_ids.*' => ['integer', \Illuminate\Validation\Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', '!=', User::ROLE_CLIENT))],
            'require_for_ready' => ['nullable', 'boolean'],
        ]);

        \App\Models\ReviewSetting::current()->update([
            'approver_ids' => array_values(array_unique(array_map('intval', $data['approver_ids'] ?? []))),
            'require_for_ready' => (bool) ($data['require_for_ready'] ?? false),
            'updated_by_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Review approvers saved.');
    }

    /** Send it back: each open comment becomes a correction on the card. */
    public function changes(Request $request, ReviewVideo $video): JsonResponse
    {
        $user = $request->user();
        abort_unless($video->reviewableBy($user), 403);
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $note = trim((string) $request->input('note', '')) ?: null;

        $card = $video->todo;
        $open = $video->comments()->whereNull('resolved_at')->whereNull('correction_todo_id')->get();
        if ($open->isEmpty() && ! $note) {
            return response()->json(['message' => 'Add a comment (or a note) saying what to change.'], 422);
        }

        DB::transaction(function () use ($video, $card, $user, $open, $note) {
            foreach ($open as $comment) {
                $agreed = $comment->likers()->count();
                $this->asCorrection($video, $comment, $user, $agreed ? ' (+'.$agreed.')' : '', reopen: false);
            }

            $video->forceFill(['status' => ReviewVideo::CHANGES, 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $card->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null, 'ready_at' => null, 'publish_at' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null])->save();
            // Back to the editor's To do (not straight into Editing): they start it when they pick it up.
            $card->review(Todo::REVIEW_REJECTED, $user, $note ?? $open->count().' '.Str::plural('change', $open->count()).' from review of v'.$video->version, Todo::STATUS_WAITING);
        });

        $this->tell(collect([$card->user])->filter(), $video, 'Changes asked: '.$card->title,
            $user->name.' asked for '.($open->count() ? $open->count().' '.Str::plural('change', $open->count()) : 'changes').' on version '.$video->version.'.'.($note ? ' '.$note : ''));

        return response()->json($this->state($video->fresh(), $user));
    }

    /** Ask Gemini to check it again (after a failure, or a raised limit). */
    public function recheck(Request $request, ReviewVideo $video): JsonResponse
    {
        abort_unless($video->reviewableBy($request->user()) || $video->uploaded_by_id === $request->user()->id, 403);
        abort_unless($video->hasFile(), 422, 'The file is gone.');
        abort_if($video->version > 1, 422, 'Only the first version gets the AI check. Check this one against the changes asked.');
        abort_if($video->ai_status === 'running' && $video->ai_lease_until?->isFuture(), 422, 'The check is running.');

        $video->forceFill(['ai_status' => 'queued', 'ai_stage' => null, 'ai_error' => null, 'ai_attempts' => 0, 'ai_lease_until' => null])->save();
        defer(fn () => VideoCheck::run($video, self::CHECK_SLICE));

        return response()->json($this->state($video->fresh(), $request->user()));
    }

    // ——— Helpers ———

    /**
     * The cut goes for review: corrections that came from the last review
     * are taken as fixed by this version, and the card is marked done.
     */
    private function sendForReview(Todo $card, ReviewVideo $video, User $user): void
    {
        $fromReview = ReviewComment::query()->whereNotNull('correction_todo_id')
            ->whereHas('video', fn ($q) => $q->where('todo_id', $card->id))->pluck('correction_todo_id');

        foreach ($card->corrections()->open()->whereIn('id', $fromReview)->get() as $fix) {
            $fix->moveTo(Todo::STATUS_COMPLETED, $user, 'Fixed in v'.$video->version);
        }
        ReviewComment::query()->whereIn('correction_todo_id', $fromReview)->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by_id' => $user->id]);

        // Older versions waiting for review are superseded.
        ReviewVideo::query()->where('todo_id', $card->id)->where('id', '!=', $video->id)->where('status', ReviewVideo::PENDING)
            ->update(['status' => ReviewVideo::CHANGES, 'review_note' => 'Replaced by v'.$video->version]);

        if ($card->corrections()->open()->exists()) {
            return; // Other corrections still open: the editor finishes them, then marks it done.
        }

        $card->moveTo(Todo::STATUS_COMPLETED, $user, 'Uploaded v'.$video->version.' for review');
    }

    /** @return array<string, mixed> */
    private function state(ReviewVideo $video, User $viewer): array
    {
        $video->loadMissing('todo');

        return [
            'id' => $video->id,
            'version' => $video->version,
            'status' => $video->status,
            'status_label' => $video->statusLabel(),
            'review_note' => $video->review_note,
            'reviewed_by' => $video->reviewedBy?->name,
            'reviewed_at' => $video->reviewed_at?->format('j M, g:i A'),
            'card_status' => $video->todo?->status,
            'can_review' => $video->reviewableBy($viewer),
            'sign_off' => $signOff = $video->signOff(),
            'latest' => $this->isLatest($video),
            'versions' => ReviewVideo::query()->where('todo_id', $video->todo_id)->orderBy('version')
                ->get(['id', 'version', 'status', 'created_at'])
                ->map(fn (ReviewVideo $v) => [
                    'id' => $v->id, 'version' => $v->version, 'status' => $v->status,
                    'label' => ReviewVideo::STATUSES[$v->status] ?? $v->status, 'when' => $v->created_at->format('j M, g:i A'),
                    'url' => route('reviews.show', $v), 'current' => $v->id === $video->id,
                ])->values()->all(),
            'previous_changes' => $this->previousChanges($video),
            // Only the first version gets the AI check; later ones are checked
            // against previous_changes.
            'ai_applies' => $video->version === 1,
            'adopted' => $video->allComments()->whereNull('parent_id')->get(['body', 'at_seconds'])
                ->map(fn ($c) => $c->body.'|'.($c->at_seconds === null ? '' : number_format((float) $c->at_seconds, 1, '.', '')))->values()->all(),
            'i_approved' => $video->approvals()->where('user_id', $viewer->id)->exists(),
            'can_approve_all' => $this->canApproveForAll($viewer),
            'ai' => [
                'status' => $video->ai_status,
                'verdict' => $video->ai_verdict,
                'verdict_label' => $video->verdictLabel(),
                'report' => $video->ai_report,
                'error' => $video->ai_error,
                'stage' => $video->ai_stage,
                'progress' => $video->ai_progress,
                'stage_secs' => $video->ai_stage_at ? max(0, (int) $video->ai_stage_at->diffInSeconds(now(), true)) : 0,
                'expect_secs' => (int) round(20 + ($video->duration ?? 45) * 1.2),
                'attempt' => $video->ai_attempts,
            ],
            'comments' => $video->comments()->with(['user:id,name,avatar_path', 'likers:id,name', 'replies.user:id,name,avatar_path', 'replies.likers:id,name'])->get()
                ->map(fn (ReviewComment $c) => $this->commentRow($c, $viewer) + [
                    'replies' => $c->replies->map(fn (ReviewComment $r) => $this->commentRow($r, $viewer))->values()->all(),
                ])->values()->all(),
            // Everyone else on this cut in the last 15 seconds, and where they are in it.
            'watching' => DB::table('review_presence')->join('users', 'users.id', '=', 'review_presence.user_id')
                ->where('review_video_id', $video->id)->where('user_id', '!=', $viewer->id)
                ->where('seen_at', '>=', now()->subSeconds(15))
                ->get(['users.id', 'users.name', 'users.avatar_path', 'at_seconds', 'playing'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'avatar' => $this->avatar($p->avatar_path), 'at' => $p->at_seconds !== null ? (float) $p->at_seconds : null, 'playing' => (bool) $p->playing])
                ->values()->all(),
            'me' => ['id' => $viewer->id, 'name' => $viewer->name],
        ];
    }

    /**
     * What to check on this version: every change asked on the version just
     * before (the editor had to fix each one), plus older changes nobody has
     * checked yet. Older ones already checked are settled -- a "not fixed"
     * was raised again on the next version, so it is in that list instead.
     * With whether the editor marked it fixed and whether a reviewer checked
     * it, newest version first, then in the order they come in the video.
     *
     * @return list<array<string, mixed>>
     */
    private function previousChanges(ReviewVideo $video): array
    {
        $earlier = ReviewVideo::query()->where('todo_id', $video->todo_id)->where('version', '<', $video->version)->pluck('version', 'id');
        if ($earlier->isEmpty()) {
            return [];
        }
        $last = $earlier->search($earlier->max());

        return ReviewComment::query()->whereIn('review_video_id', $earlier->keys())->whereNotNull('correction_todo_id')
            ->where(fn ($q) => $q->where('review_video_id', $last)->orWhereNull('fix_state'))
            ->with(['user:id,name', 'video:id,version'])
            ->get()
            ->sortBy([['review_video_id', 'desc'], ['at_seconds', 'asc'], ['id', 'asc']])
            ->map(function (ReviewComment $c) {
                $fix = Todo::find($c->correction_todo_id);
                $checker = $c->fix_checked_by_id ? User::find($c->fix_checked_by_id, ['id', 'name']) : null;

                return [
                    'id' => $c->id,
                    'version' => $c->video?->version,
                    'at' => $c->at_seconds,
                    'stamp' => $c->stamp(),
                    'body' => $c->body,
                    'by' => $c->user?->name ?? 'Someone',
                    'editor_done' => $fix?->status === Todo::STATUS_COMPLETED,
                    'editor_note' => $fix && $fix->status === Todo::STATUS_COMPLETED
                        ? optional($fix->updates()->where('to_status', Todo::STATUS_COMPLETED)->latest('id')->first())->note : null,
                    'state' => $c->fix_state,
                    'checked_by' => $checker?->name,
                    'checked_at' => $c->fix_checked_at?->format('j M, g:i A'),
                ];
            })->values()->all();
    }

    private function isLatest(ReviewVideo $video): bool
    {
        return ! ReviewVideo::query()->where('todo_id', $video->todo_id)->where('version', '>', $video->version)->exists();
    }

    /** One comment, made a correction on the editor's card (in their To do). */
    private function asCorrection(ReviewVideo $video, ReviewComment $comment, User $by, string $suffix = '', bool $reopen = true): Todo
    {
        $card = $video->todo;
        $fix = Todo::create([
            'user_id' => $card->user_id,
            'assigned_by_id' => $by->id,
            'parent_id' => $card->id,
            'title' => Str::limit(($comment->stamp() ? $comment->stamp().' — ' : '').$comment->body.$suffix, 250),
            'venture' => $card->venture,
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);
        TodoUpdate::record($fix, $by, TodoUpdate::CREATED, ['to_status' => $fix->status, 'from_on' => $fix->starts_on, 'to_on' => $fix->due_on, 'note' => 'From review of v'.$video->version]);
        $comment->forceFill(['correction_todo_id' => $fix->id])->save();

        // Finished since the send back? Then it is open again.
        if ($reopen && $card->status === Todo::STATUS_COMPLETED) {
            $card->moveTo(Todo::STATUS_WAITING, $by, 'Another change from review');
        }

        return $fix;
    }

    /** @return array<string, mixed> */
    private function commentRow(ReviewComment $c, User $viewer): array
    {
        return [
            'id' => $c->id,
            'at' => $c->at_seconds,
            'stamp' => $c->stamp(),
            'body' => $c->body,
            'by' => $c->user?->name ?? 'Someone',
            'by_id' => $c->user_id,
            'avatar' => $this->avatar($c->user?->avatar_path),
            'mine' => $c->user_id === $viewer->id,
            'resolved' => $c->resolved_at !== null,
            'correction' => $c->correction_todo_id !== null,
            'when' => $c->created_at?->diffForHumans(null, true, true),
            'edited' => $c->updated_at && $c->created_at && $c->updated_at->gt($c->created_at->copy()->addSeconds(5)) && $c->resolved_at === null,
            'created' => $c->created_at?->toIso8601String(),
            'likes' => $c->likers->count(),
            'liked' => $c->likers->contains('id', $viewer->id),
            'liked_by' => $c->likers->pluck('name')->map(fn ($n) => Str::before($n, ' '))->values()->all(),
        ];
    }

    private function avatar(?string $path): ?string
    {
        return $path && is_file(public_path($path)) ? asset($path) : null;
    }

    private function authoriseUpload(Todo $card, User $user): void
    {
        abort_unless($card->user && ($card->user_id === $user->id || $user->manages($card->user)), 403);
        abort_if($card->status === Todo::STATUS_CANCELLED, 422, 'This card was removed.');
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function upload(string $id, User $user): array
    {
        abort_unless(Str::isUuid($id) && is_file($this->tmp($id.'.json')), 404, 'That upload has expired. Start again.');
        $meta = json_decode((string) File::get($this->tmp($id.'.json')), true);
        abort_unless(($meta['user_id'] ?? null) === $user->id, 404);

        return [$meta, $this->tmp($id.'.part')];
    }

    private function tmp(string $name = ''): string
    {
        return storage_path('app/private/review-uploads'.($name !== '' ? '/'.$name : ''));
    }

    private function typeOf(?string $type, string $name): ?string
    {
        if ($type && in_array($type, self::TYPES, true)) {
            return $type;
        }

        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'm4v' => 'video/x-m4v',
            default => null,
        };
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function reviewersOf(Todo $card): \Illuminate\Support\Collection
    {
        $editor = $card->user;

        return User::query()->where('role', User::ROLE_ADMIN)->get()
            ->merge($editor?->managers()->get() ?? collect())
            ->unique('id')
            ->reject(fn (User $u) => $u->id === $editor?->id);
    }

    private function tell($people, ReviewVideo $video, string $title, string $body): void
    {
        try {
            Notification::send($people, new VideoReviewUpdate($video, $title, $body));
        } catch (Throwable $e) {
            Log::warning('Review notification failed.', ['video' => $video->id, 'error' => $e->getMessage()]);
        }
    }
}
