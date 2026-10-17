<?php

namespace App\Tools\Studio;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class PostAnnouncement extends Tool
{
    public function name(): string
    {
        return 'post_announcement';
    }

    public function title(): string
    {
        return 'Post an announcement';
    }

    public function group(): string
    {
        return 'Team';
    }

    public function description(): string
    {
        return 'Posts an announcement to the whole team: it shows on everyone\'s dashboard and every staff member gets a push '
            .'notification at once. Optionally also shown to clients in their portal. USE WHEN asked "announce that the office is '
            .'closed Monday", "tell the team…". Confirm the exact wording with the person first -- it goes out immediately.';
    }

    public function permission(): ?string
    {
        return 'announcements.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'title' => ['type' => 'string', 'description' => 'The headline.'],
            'body' => ['type' => 'string', 'description' => 'The message.'],
            'visible_to_clients' => ['type' => 'boolean', 'description' => 'Also show it in client portals. Defaults to false.'],
        ], ['title', 'body']);
    }

    public function handle(array $arguments, User $user): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        $body = trim((string) ($arguments['body'] ?? ''));
        if ($title === '' || $body === '' || mb_strlen($title) > 255 || mb_strlen($body) > 5000) {
            throw new ToolException('Give a title (up to 255 characters) and a body (up to 5000).');
        }

        $a = Announcement::create(['title' => $title, 'body' => $body, 'is_active' => true,
            'visible_to_clients' => (bool) ($arguments['visible_to_clients'] ?? false), 'created_by' => $user->id]);

        // The same push the Announcements screen sends.
        try {
            Notification::send(User::staff()->where('id', '!=', $user->id)->get(), new AnnouncementPosted($a));
        } catch (Throwable $e) {
            Log::error('Announcement push failed.', ['announcement_id' => $a->id, 'error' => $e->getMessage()]);
        }

        return ['id' => $a->id, 'posted' => true, 'to_clients' => $a->visible_to_clients];
    }
}
