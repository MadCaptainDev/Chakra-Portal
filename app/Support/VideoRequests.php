<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\Todo;
use App\Models\User;
use App\Models\VideoRequest;
use App\Notifications\VentureUpdate;
use App\Services\Planner\PlannerPropertyWrite;
use App\Services\Planner\PublishDateMove;
use Throwable;

/**
 * Cancel and move need an admin. The owner asked for it after videos were
 * moved and cancelled on the board and in the planner without anyone
 * knowing. Admins act directly; anyone else's cancel or planner move becomes
 * a VideoRequest, and approving it does exactly what was asked.
 *
 * Exception: a card added today by mistake and never started may still be
 * removed freely.
 */
class VideoRequests
{
    /** Planner statuses that take a video off the plan: an admin's call. */
    public const STOP_STATUSES = ['Canceled', 'Cancelled', 'Hold'];

    public static function needsApproval(User $user): bool
    {
        // Admins, and anyone trusted with Chakra Planner → Manage, decide directly.
        return ! $user->isAdmin() && ! $user->can('planner.manage');
    }

    /** A card added by mistake today and never touched is the editor's to remove. */
    public static function freeToRemove(Todo $todo): bool
    {
        return $todo->created_at?->isToday()
            && $todo->status === Todo::STATUS_WAITING
            && ! $todo->updates()->where('to_status', Todo::STATUS_STARTED)->exists();
    }

    public static function ask(User $user, string $kind, string $title, array $payload, ?Todo $todo = null, ?ContentItem $item = null): VideoRequest
    {
        $open = VideoRequest::query()->where('status', 'pending')->where('kind', $kind)
            ->when($todo, fn ($q) => $q->where('todo_id', $todo->id))
            ->when($item, fn ($q) => $q->where('content_item_id', $item->id))->first();
        if ($open) {
            $open->forceFill(['payload' => $payload, 'user_id' => $user->id])->save();

            return $open;
        }

        $req = VideoRequest::create(['kind' => $kind, 'todo_id' => $todo?->id, 'content_item_id' => $item?->id ?? $todo?->content_item_id,
            'user_id' => $user->id, 'title' => \Illuminate\Support\Str::limit($title, 250), 'payload' => $payload]);

        rescue(fn () => \Illuminate\Support\Facades\Notification::send(User::query()->where('role', User::ROLE_ADMIN)->get(),
            new VentureUpdate('Approval needed: '.$req->summary(), $user->name.' · '.$req->title, 'video-request', route('editor-updates').'#requests')), null, false);

        return $req;
    }

    /** Approve: do what was asked, as the admin. */
    public static function approve(VideoRequest $req, User $admin): VideoRequest
    {
        $p = $req->payload ?? [];
        try {
            match ($req->kind) {
                'cancel' => self::cancel($req->todo, $admin, 'Cancelled (approved'.($req->user ? ' — asked by '.$req->user->name : '').'): '.($p['note'] ?? 'no reason given')),
                'move' => app(PublishDateMove::class)->move($req->contentItem, $p['to'] ?? null, $p['from'] ?? null, false, $admin),
                'status' => app(PlannerPropertyWrite::class)->update($req->contentItem, ['status' => $p['status']], [], $admin),
            };
            $req->forceFill(['status' => 'approved', 'decided_by_id' => $admin->id, 'decided_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
            $req->forceFill(['status' => 'failed', 'decided_by_id' => $admin->id, 'decided_at' => now(), 'decision_note' => \Illuminate\Support\Str::limit($e->getMessage(), 250)])->save();
        }
        self::tell($req);

        return $req;
    }

    public static function refuse(VideoRequest $req, User $admin, ?string $note = null): VideoRequest
    {
        $req->forceFill(['status' => 'refused', 'decided_by_id' => $admin->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        self::tell($req);

        return $req;
    }

    /** The cancel itself -- the same thing the board's Remove did before approval existed. */
    public static function cancel(?Todo $todo, User $by, string $note): void
    {
        if (! $todo || $todo->status === Todo::STATUS_CANCELLED || $todo->posted_at) {
            return;
        }
        $todo->moveTo(Todo::STATUS_CANCELLED, $by, $note);
        $todo->corrections()->open()->get()->each(fn (Todo $fix) => $fix->moveTo(Todo::STATUS_CANCELLED, $by, 'Video removed'));
        $todo->forceFill(['ready_at' => null, 'publish_at' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null])->save();
    }

    private static function tell(VideoRequest $req): void
    {
        $verb = ['approved' => 'approved', 'refused' => 'refused', 'failed' => 'approved, but it did not go through'][$req->status] ?? $req->status;
        rescue(fn () => $req->user?->notify(new VentureUpdate('Your request was '.$verb, $req->summary().' · '.$req->title.($req->decision_note ? ' — '.$req->decision_note : ''), 'video-request', route('my.editing'))), null, false);
    }
}
