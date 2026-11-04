<?php

namespace App\Models;

use App\Services\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One line in a teammate's notification inbox (the mobile app's bell).
 * Written by FcmChannel for every push-capable notification, before it
 * knows whether any device will receive the push.
 */
class AppNotification extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'body', 'url', 'tag'];

    protected $casts = ['read_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(User $user, string $notificationClass, PushMessage $message): self
    {
        return self::create([
            'user_id' => $user->id,
            // "TodoAssigned" -> "todo_assigned": stable for the app to switch icons on.
            'type' => Str::snake(class_basename($notificationClass)),
            'title' => Str::limit($message->title, 250, '…'),
            'body' => $message->body,
            'url' => Str::limit($message->url, 500, ''),
            'tag' => $message->tag ? Str::limit($message->tag, 120, '') : null,
        ]);
    }

    /** "https://chakragroups.in/shoots/4?x=1" -> "/shoots/4?x=1"; a bare path passes through. */
    public static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return '/'.ltrim($path, '/').($query ? '?'.$query : '');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            // Notifications store route() URLs (absolute); the app routes on the path.
            'url' => url($this->url),
            'path' => self::pathOf($this->url),
            'tag' => $this->tag,
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
