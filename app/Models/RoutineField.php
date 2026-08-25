<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineField extends Model
{
    use HasFactory;

    public const TYPE_NUMBER = 'number';
    public const TYPE_TEXT = 'text';
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPES = [
        self::TYPE_NUMBER => 'Number',
        self::TYPE_TEXT => 'Text',
        self::TYPE_BOOLEAN => 'Yes / No',
    ];

    protected $fillable = [
        'routine_id',
        'checkpoint_id',
        'label',
        'key',
        'type',
        'default_value',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(RoutineCheckpoint::class, 'checkpoint_id');
    }

    /**
     * Default posted when the employee ticks complete without typing.
     */
    public function resolvedDefault(): mixed
    {
        return match ($this->type) {
            self::TYPE_NUMBER => $this->default_value !== null && $this->default_value !== ''
                ? (float) $this->default_value
                : 0,
            self::TYPE_BOOLEAN => filter_var($this->default_value, FILTER_VALIDATE_BOOLEAN),
            default => $this->default_value ?? '',
        };
    }
}
