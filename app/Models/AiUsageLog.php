<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per call to a paid LLM API -- see the migration for why every
 * call is logged rather than estimated after the fact.
 */
class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'purpose',
        'model',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'portfolio_item_id',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost_usd' => 'float',
        'created_at' => 'datetime',
    ];

    /**
     * USD per million tokens, input/output -- Anthropic's published first-party
     * rates as of Aug 2026. A model missing here costs $0 in the log rather
     * than throwing, so a forgotten price update degrades to "untracked
     * spend", not a broken generation feature.
     */
    private const PRICING_PER_MILLION = [
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        'claude-sonnet-4-5-20250929' => ['input' => 3.00, 'output' => 15.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
    ];

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(PortfolioItem::class);
    }

    /**
     * Logs one call and returns the row. $usage is Anthropic's own
     * response.usage object -- {input_tokens, output_tokens} -- passed
     * through as the API returned it rather than re-derived, so the cost
     * recorded here is never more accurate than what Anthropic actually
     * billed for.
     */
    public static function record(string $purpose, string $model, int $inputTokens, int $outputTokens, ?PortfolioItem $item = null): self
    {
        return self::create([
            'purpose' => $purpose,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_usd' => self::estimateCost($model, $inputTokens, $outputTokens),
            'portfolio_item_id' => $item?->id,
        ]);
    }

    private static function estimateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $rate = self::PRICING_PER_MILLION[$model] ?? ['input' => 0.0, 'output' => 0.0];

        return round(($inputTokens / 1_000_000 * $rate['input']) + ($outputTokens / 1_000_000 * $rate['output']), 4);
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereDate('created_at', '>=', now()->startOfMonth());
    }

    /** @return array{calls: int, input_tokens: int, output_tokens: int, cost_usd: float} */
    public static function summary(Builder $query): array
    {
        $row = $query->selectRaw('count(*) as calls, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(estimated_cost_usd), 0) as cost_usd')
            ->first();

        return [
            'calls' => (int) $row->calls,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cost_usd' => (float) $row->cost_usd,
        ];
    }
}
