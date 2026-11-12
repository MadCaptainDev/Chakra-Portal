<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Who must approve a cut, and whether Video ready waits for it. One row. */
class ReviewSetting extends Model
{
    protected $fillable = ['approver_ids', 'require_for_ready', 'updated_by_id'];

    protected $casts = ['approver_ids' => 'array', 'require_for_ready' => 'boolean'];

    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create(['approver_ids' => [], 'require_for_ready' => true]);
    }

    /** @return Collection<int, User> the required approvers, in their order, who still have an account */
    public function approvers(): Collection
    {
        $ids = array_map('intval', $this->approver_ids ?? []);
        $users = User::query()->whereIn('id', $ids)->where('role', '!=', User::ROLE_CLIENT)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $users->get($id))->filter()->values();
    }

    public function isApprover(User $user): bool
    {
        return in_array($user->id, array_map('intval', $this->approver_ids ?? []), true);
    }
}
