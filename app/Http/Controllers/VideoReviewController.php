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

        return view('reviews.index', [
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

        return response()->json($this->state($video->fresh(), $request->user()));
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
                'ai_status' => config('ai.review.enabled', true) ? 'queued' : 'none',
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

        if ($video->ai_status === 'queued') {
            defer(fn () => VideoCheck::run($video->fresh()));
        }

        return response()->json(['video' => $video->summary(), 'url' => route('reviews.show', $video)], 201);
    }

    // ——— Commenting ———

    public function comment(Request $request, ReviewVideo $video): JsonResponse
    {
        abort_unless($video->visibleTo($request->user()), 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'at_seconds' => ['nullable', 'numeric', 'min:0', 'max:36000'],
        ]);

        ReviewComment::create(['review_video_id' => $video->id, 'user_id' => $request->user()->id] + $data);

        return response()->json($this->state($video->fresh(), $request->user()), 201);
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
        $comment->delete();

        return response()->json($this->state($video->fresh(), $request->user()));
    }

    // ——— The verdict ———

    public function approve(Request $request, ReviewVideo $video): JsonResponse
    {
        $user = $request->user();
        abort_unless($video->reviewableBy($user), 403);
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $note = trim((string) $request->input('note', '')) ?: null;

        $card = $video->todo;
        if ($card->status !== Todo::STATUS_COMPLETED) {
            return response()->json(['message' => 'The card is not finished: the editor has more to do on it.'], 422);
        }

        $video->forceFill(['status' => ReviewVideo::APPROVED, 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
        $card->review(Todo::REVIEW_APPROVED, $user, $note ?? 'Approved v'.$video->version.' in review');

        $this->tell(collect([$card->user])->filter(), $video, 'Approved: '.$card->title,
            $user->name.' approved version '.$video->version.'. Make it Video ready with its publishing time.'.($note ? ' Note: '.$note : ''));

        return response()->json($this->state($video->fresh(), $user));
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
                $title = Str::limit(($comment->stamp() ? $comment->stamp().' — ' : '').$comment->body, 250);
                $fix = Todo::create([
                    'user_id' => $card->user_id,
                    'assigned_by_id' => $user->id,
                    'parent_id' => $card->id,
                    'title' => $title,
                    'venture' => $card->venture,
                    'starts_on' => today()->toDateString(),
                    'due_on' => today()->toDateString(),
                ]);
                TodoUpdate::record($fix, $user, TodoUpdate::CREATED, ['to_status' => $fix->status, 'from_on' => $fix->starts_on, 'to_on' => $fix->due_on, 'note' => 'From review of v'.$video->version]);
                $comment->forceFill(['correction_todo_id' => $fix->id])->save();
            }

            $video->forceFill(['status' => ReviewVideo::CHANGES, 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $card->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null, 'ready_at' => null, 'publish_at' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null])->save();
            $card->review(Todo::REVIEW_REJECTED, $user, $note ?? $open->count().' '.Str::plural('change', $open->count()).' from review of v'.$video->version);
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

        $video->forceFill(['ai_status' => 'queued', 'ai_error' => null])->save();
        defer(fn () => VideoCheck::run($video->fresh()));

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
            'ai' => [
                'status' => $video->ai_status,
                'verdict' => $video->ai_verdict,
                'verdict_label' => $video->verdictLabel(),
                'report' => $video->ai_report,
                'error' => $video->ai_error,
            ],
            'comments' => $video->comments()->with('user:id,name,avatar_path')->get()->map(fn (ReviewComment $c) => [
                'id' => $c->id,
                'at' => $c->at_seconds,
                'stamp' => $c->stamp(),
                'body' => $c->body,
                'by' => $c->user?->name ?? 'Someone',
                'mine' => $c->user_id === $viewer->id,
                'resolved' => $c->resolved_at !== null,
                'correction' => $c->correction_todo_id !== null,
                'when' => $c->created_at?->diffForHumans(),
            ])->values()->all(),
        ];
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
