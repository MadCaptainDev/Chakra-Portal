<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One export run through the Video Checker. See the create_video_checks_table
 * migration: the video never leaves the editor's device, only this verdict.
 */
class VideoCheck extends Model
{
    /** Where the video is going; the browser checks against these (resources/js/video-check.js). */
    public const PRESETS = [
        'reel' => 'Instagram Reel',
        'short' => 'YouTube Short',
        'youtube' => 'YouTube video',
        'status' => 'WhatsApp Status',
    ];

    public const VERDICTS = ['pass', 'warn', 'fail'];

    protected $fillable = ['user_id', 'label', 'file_name', 'file_size', 'preset', 'verdict', 'results'];

    protected $casts = [
        'results' => 'array',
        'file_size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function presetLabel(): string
    {
        return self::PRESETS[$this->preset] ?? $this->preset;
    }

    /** Counts of each status, for the history row: ['fail' => 1, 'warn' => 2]. */
    public function tally(): array
    {
        return collect($this->results)->countBy('status')->only(['fail', 'warn'])->all();
    }
}
