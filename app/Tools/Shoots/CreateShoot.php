<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;
use Throwable;

class CreateShoot extends Tool
{
    public function name(): string
    {
        return 'create_shoot';
    }

    public function title(): string
    {
        return 'Plan a shoot';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Put a shoot on the studio calendar: title, when it starts (and optionally ends), '
            .'where, and for which client. Starts as "planned" unless the person says it is confirmed. '
            .'Times are the studio\'s local time (India). Nobody is notified by this tool; add people '
            .'with assign_shoot_crew afterwards. Check list_shoots first if the person might mean a '
            .'shoot that already exists -- do not create a duplicate.';
    }

    public function permission(): ?string
    {
        return 'shoots.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'title' => ['type' => 'string', 'description' => 'Short name, e.g. "SVA Ortho clinic reels".'],
            'starts_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM, 24-hour, studio time.'],
            'ends_at' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD HH:MM, not before starts_at.'],
            'client' => ['type' => 'string', 'description' => 'Optional. Client id or portal name. Leave out for in-house shoots.'],
            'location' => ['type' => 'string', 'description' => 'Optional.'],
            'status' => ['type' => 'string', 'enum' => [Shoot::STATUS_PLANNED, Shoot::STATUS_CONFIRMED], 'description' => 'Optional; default planned.'],
            'notes' => ['type' => 'string', 'description' => 'Optional. Brief for the crew.'],
        ], ['title', 'starts_at']);
    }

    public function handle(array $arguments, User $user): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            throw new ToolException('Give the shoot a title of up to 160 characters.');
        }

        $startsAt = self::time($arguments['starts_at'] ?? null, 'starts_at');
        $endsAt = filled($arguments['ends_at'] ?? null) ? self::time($arguments['ends_at'], 'ends_at') : null;

        if ($endsAt && $endsAt->lt($startsAt)) {
            throw new ToolException('ends_at is before starts_at.');
        }
        if ($startsAt->lt(now()->subDay())) {
            throw new ToolException('That start time is in the past. Check the date with the person.');
        }

        $status = $arguments['status'] ?? Shoot::STATUS_PLANNED;
        if (! in_array($status, [Shoot::STATUS_PLANNED, Shoot::STATUS_CONFIRMED], true)) {
            throw new ToolException('status must be planned or confirmed.');
        }

        $client = filled($arguments['client'] ?? null) ? ClientResolver::resolve($arguments['client']) : null;

        $shoot = new Shoot([
            'title' => $title,
            'client_id' => $client?->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => isset($arguments['location']) ? mb_substr((string) $arguments['location'], 0, 255) : null,
            'status' => $status,
            'notes' => isset($arguments['notes']) ? mb_substr((string) $arguments['notes'], 0, 5000) : null,
        ]);
        $shoot->created_by_id = $user->id;
        $shoot->save();

        return [
            'created' => true,
            'shoot_id' => $shoot->id,
            'title' => $shoot->title,
            'client' => $client?->name,
            'starts_at' => $shoot->starts_at->format('Y-m-d H:i'),
            'ends_at' => $shoot->ends_at?->format('Y-m-d H:i'),
            'status' => $shoot->statusLabel(),
            'next' => 'Add crew with assign_shoot_crew.',
            'open_in_portal' => route('shoots.show', $shoot),
        ];
    }

    public static function time(mixed $value, string $field): Carbon
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $value)) {
            throw new ToolException("{$field} must be YYYY-MM-DD HH:MM (24-hour), e.g. 2026-10-12 09:30.");
        }

        try {
            return Carbon::parse(str_replace('T', ' ', $value));
        } catch (Throwable) {
            throw new ToolException("{$field} is not a real date and time.");
        }
    }
}
