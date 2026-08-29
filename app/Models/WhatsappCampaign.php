<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A broadcast: one approved Meta template, sent to everyone in one
 * phonebook.
 *
 * status walks draft -> scheduled -> sending -> completed, with failed and
 * cancelled as the two ways off that path -- the exact strings
 * resources/views/components/badge.blade.php already knows how to colour.
 */
class WhatsappCampaign extends Model
{
    protected $fillable = [
        'name',
        'meta_template_name',
        'meta_template_language',
        'phonebook_id',
        'variable_mapping',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'batch_size',
        'message_delay_ms',
        'created_by_id',
    ];

    protected $casts = [
        'variable_mapping' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'batch_size' => 'integer',
        'message_delay_ms' => 'integer',
    ];

    public function phonebook(): BelongsTo
    {
        return $this->belongsTo(WhatsappPhonebook::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WhatsappCampaignLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Counts keyed by each log status, plus the total row count -- the shape
     * a progress bar needs without it having to know the status vocabulary
     * itself.
     *
     * @return array<string, int>
     */
    public function progress(): array
    {
        $counts = $this->logs()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $progress = [
            'pending' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
        ];

        foreach ($counts as $status => $count) {
            $progress[$status] = (int) $count;
        }

        $progress['total'] = array_sum($progress);

        return $progress;
    }

    /**
     * Whether this campaign's scheduled moment has arrived -- the condition
     * a dispatcher checks before promoting it from scheduled to sending.
     */
    public function isDue(): bool
    {
        return $this->status === 'scheduled' && (bool) $this->scheduled_at?->isPast();
    }
}
