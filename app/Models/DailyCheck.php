<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A team's end-of-day checklist for one day -- see App\Support\DailyReport. */
class DailyCheck extends Model
{
    protected $fillable = ['date', 'team', 'items', 'status', 'reported_by_id', 'message'];

    protected $casts = [
        'date' => 'date',
        'items' => 'array',
    ];

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    /** @return list<array{label: string, ok: bool, note: ?string}> */
    public function missed(): array
    {
        return array_values(array_filter($this->items ?? [], fn (array $i) => ! $i['ok']));
    }
}
