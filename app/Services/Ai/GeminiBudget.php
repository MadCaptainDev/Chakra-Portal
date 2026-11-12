<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\User;
use App\Notifications\AiBudgetAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A monthly spend limit on Google Gemini, enforced for every call the portal
 * makes -- the reel transcriber, competitor analysis, captions, and anything
 * added later -- because it sits on Laravel's HTTP client itself rather than
 * in each feature (see AppServiceProvider):
 *
 *   before  a billable Gemini request is refused, with nothing sent, once
 *           this month's spend plus a small reserve would pass the limit
 *   after   the tokens Google reports in usageMetadata are priced
 *           (config/ai.php) and written to ai_usage
 *
 * Admins are told once at 80% and once when the limit is reached each month.
 *
 * The figure is the portal's own estimate from Google's token counts and
 * published prices, rounded up. Google's bill is the real one; a budget
 * alert in Google Cloud billing is the backstop for anything this misses
 * (a key used outside the portal, say).
 */
class GeminiBudget
{
    private const HOST = 'generativelanguage.googleapis.com';

    /** Endpoints Google charges for. File uploads and status polls are free. */
    private const BILLABLE = '/:(generateContent|streamGenerateContent|embedContent|batchEmbedContents|countTokens)$/';

    /** What the current call is for, set by during(). */
    private static string $feature = 'other';

    private static ?int $userId = null;

    /** Run $work with its Gemini calls recorded under $feature (and the person asking). */
    public static function during(string $feature, callable $work, ?User $by = null): mixed
    {
        [$feature0, $user0] = [self::$feature, self::$userId];
        [self::$feature, self::$userId] = [$feature, $by?->id ?? auth()->id()];

        try {
            return $work();
        } finally {
            [self::$feature, self::$userId] = [$feature0, $user0];
        }
    }

    public static function cap(): float
    {
        return (float) (AiSetting::current()->gemini_monthly_cap_usd ?? 5);
    }

    public static function spentThisMonth(): float
    {
        return (float) DB::table('ai_usage')->where('provider', 'gemini')
            ->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    public static function left(): float
    {
        return max(0, self::cap() - self::spentThisMonth());
    }

    /** @return array<string, mixed> for the settings page */
    public static function summary(): array
    {
        $rows = DB::table('ai_usage')->where('provider', 'gemini')->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('feature, COUNT(*) calls, SUM(cost_usd) cost, SUM(input_tokens + audio_tokens) input, SUM(output_tokens) output')
            ->groupBy('feature')->orderByDesc('cost')->get();
        $spent = (float) $rows->sum('cost');
        $cap = self::cap();

        return [
            'cap' => $cap,
            'spent' => $spent,
            'left' => max(0, $cap - $spent),
            'pct' => $cap > 0 ? min(100, round($spent / $cap * 100, 1)) : 100,
            'calls' => (int) $rows->sum('calls'),
            'features' => $rows,
            'blocked' => $spent + (float) config('ai.gemini_reserve_usd', 0.05) >= $cap,
            'resets' => now()->startOfMonth()->addMonth(),
        ];
    }

    public static function isBillable(RequestInterface $request): bool
    {
        return $request->getUri()->getHost() === self::HOST
            && preg_match(self::BILLABLE, $request->getUri()->getPath());
    }

    /**
     * HTTP client request middleware: refuse a billable call over the limit.
     *
     * @throws GeminiBudgetExceeded
     */
    public static function guard(RequestInterface $request): RequestInterface
    {
        if (! self::isBillable($request)) {
            return $request;
        }

        $spent = self::spentThisMonth();
        $cap = self::cap();

        if ($spent + (float) config('ai.gemini_reserve_usd', 0.05) >= $cap) {
            self::alert(100, $spent, $cap);

            throw new GeminiBudgetExceeded(sprintf(
                'This month\'s Gemini limit of $%s is used up ($%s spent), so nothing was sent to Google. It resets on %s, or an admin can raise it under AI settings.',
                number_format($cap, 2), number_format($spent, 2), now()->startOfMonth()->addMonth()->format('j M'),
            ));
        }

        return $request;
    }

    /** HTTP client response middleware: price what Google reports and keep it. */
    public static function meter(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (! self::isBillable($request) || $response->getStatusCode() >= 400) {
            return $response;
        }

        try {
            $body = (string) $response->getBody();
            $response->getBody()->rewind();
            $usage = self::usageFrom($body);
            if ($usage === null) {
                return $response;
            }

            preg_match('#/models/([^/:]+):#', $request->getUri()->getPath(), $m);
            self::record($m[1] ?? 'unknown', $usage['input'], $usage['audio'], $usage['output']);
        } catch (Throwable $e) {
            Log::warning('Gemini usage could not be recorded.', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    /**
     * Tokens from a generateContent reply (or the last chunk of a stream).
     *
     * @return array{input: int, audio: int, output: int}|null
     */
    public static function usageFrom(string $body): ?array
    {
        $json = json_decode($body, true);
        if (is_array($json) && array_is_list($json)) {
            $json = end($json) ?: null; // streamed: usage is on the last chunk
        }
        $meta = is_array($json) ? ($json['usageMetadata'] ?? null) : null;
        if (! is_array($meta)) {
            return null;
        }

        $prompt = (int) ($meta['promptTokenCount'] ?? 0);
        $audio = (int) collect($meta['promptTokensDetails'] ?? [])->where('modality', 'AUDIO')->sum('tokenCount');
        $output = (int) ($meta['candidatesTokenCount'] ?? 0) + (int) ($meta['thoughtsTokenCount'] ?? 0);

        return ['input' => max(0, $prompt - $audio), 'audio' => $audio, 'output' => $output];
    }

    public static function price(string $model): array
    {
        foreach ((array) config('ai.gemini_prices') as $pattern => $price) {
            if ($pattern !== '*' && str_contains(strtolower($model), $pattern)) {
                return $price;
            }
        }

        return config('ai.gemini_prices.*');
    }

    public static function record(string $model, int $input, int $audio, int $output): float
    {
        $p = self::price($model);
        $cost = ($input * $p['input'] + $audio * $p['audio'] + $output * $p['output']) / 1_000_000;

        DB::table('ai_usage')->insert([
            'provider' => 'gemini',
            'model' => mb_substr($model, 0, 80),
            'feature' => self::$feature,
            'input_tokens' => $input,
            'audio_tokens' => $audio,
            'output_tokens' => $output,
            'cost_usd' => round($cost, 6),
            'user_id' => self::$userId ?? auth()->id(),
            'created_at' => now(),
        ]);

        $spent = self::spentThisMonth();
        $cap = self::cap();
        if ($cap > 0 && $spent >= $cap * 0.8) {
            self::alert($spent >= $cap ? 100 : 80, $spent, $cap);
        }

        return $cost;
    }

    /** Tell the admins, once per level per month. */
    private static function alert(int $level, float $spent, float $cap): void
    {
        $settings = AiSetting::current();
        $mark = now()->format('Y-m').':'.$level;
        [$month, $told] = array_pad(explode(':', (string) $settings->gemini_cap_alerted), 2, '0');
        if ($month === now()->format('Y-m') && (int) $told >= $level) {
            return;
        }
        $settings->forceFill(['gemini_cap_alerted' => $mark])->save();

        try {
            Notification::send(User::query()->where('role', User::ROLE_ADMIN)->get(), new AiBudgetAlert($level, $spent, $cap));
        } catch (Throwable $e) {
            Log::warning('Gemini budget alert failed.', ['error' => $e->getMessage()]);
        }
    }

    /** For tests: forget what the current call was for. */
    public static function reset(): void
    {
        [self::$feature, self::$userId] = ['other', null];
    }

    public static function monthStart(): Carbon
    {
        return now()->startOfMonth();
    }
}
