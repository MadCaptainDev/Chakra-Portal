<?php

namespace App\Tools\Studio;

use App\Models\Enquiry;
use App\Models\User;
use App\Tools\Tool;

class ListEnquiries extends Tool
{
    public function name(): string
    {
        return 'list_enquiries';
    }

    public function title(): string
    {
        return 'Website enquiries';
    }

    public function group(): string
    {
        return 'Leads';
    }

    public function description(): string
    {
        return 'Enquiries that came in through the website contact form: who, phone, email, what project, their message, '
            .'when, and whether someone has read or handled it. USE WHEN asked "any new enquiries", "who contacted us this week". '
            .'Read-only; it does not mark them read.';
    }

    public function permission(): ?string
    {
        return 'enquiries.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'only_open' => ['type' => 'boolean', 'description' => 'Only ones not handled yet. Defaults to true.'],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'How far back. Defaults to 30.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $rows = Enquiry::query()
            ->when($arguments['only_open'] ?? true, fn ($q) => $q->open())
            ->where('created_at', '>=', now()->subDays((int) ($arguments['days'] ?? 30)))
            ->latest()->limit(50)->get();

        return [
            'count' => $rows->count(),
            'enquiries' => $rows->map(fn (Enquiry $e) => [
                'id' => $e->id, 'name' => $e->name, 'phone' => $e->phone, 'email' => $e->email,
                'project' => $e->project, 'message' => $e->message, 'source' => $e->source,
                'received' => $e->created_at->format('Y-m-d H:i'),
                'read' => $e->read_at !== null, 'handled' => $e->handled_at !== null,
            ])->all(),
        ];
    }
}
