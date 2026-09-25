<?php

namespace App\Tools\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Tools\Tool;

class ListProposals extends Tool
{
    public function name(): string
    {
        return 'list_proposals';
    }

    public function description(): string
    {
        return 'The studio\'s proposals, most recently changed first: id, title, client, status '
            .'(draft, sent, viewed, accepted, declined), open client comments and links. Filter by '
            .'words in the title or client name, or by status.';
    }

    public function permission(): ?string
    {
        return 'proposals.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'search' => ['type' => 'string', 'description' => 'Words in the title or the client\'s name.'],
            'status' => ['type' => 'string', 'enum' => array_keys(Proposal::STATUSES)],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Default 20.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $search = trim((string) ($arguments['search'] ?? ''));
        $status = $arguments['status'] ?? null;

        $proposals = Proposal::query()
            ->with('client')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->when(array_key_exists((string) $status, Proposal::STATUSES), fn ($q) => $q->where('status', $status))
            ->latest('updated_at')
            ->limit(max(1, min(50, (int) ($arguments['limit'] ?? 20))))
            ->get();

        return [
            'count' => $proposals->count(),
            'proposals' => $proposals->map(fn (Proposal $p) => ProposalToolSupport::summary($p))->all(),
        ];
    }
}
