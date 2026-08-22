<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded backup file. See the migration for why disk_path lives on the
 * private 'local' disk rather than App\Support\PublicUpload's public tree.
 */
class SaasBackup extends Model
{
    protected $fillable = [
        'saas_product_id',
        'disk_path',
        'size_bytes',
        'original_filename',
        'checksum',
        'taken_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'taken_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SaasProduct::class, 'saas_product_id');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('taken_at');
    }
}
