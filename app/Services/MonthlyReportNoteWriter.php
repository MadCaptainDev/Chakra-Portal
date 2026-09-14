<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Shoot;
use App\Services\Ai\ChatModel;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A first draft of the note a client reads at the top of their monthly
 * report.
 *
 * Somebody at the studio writes this by hand every month for every client,
 * from figures that are already on the screen in front of them. That is the
 * shape of work worth drafting: the facts are known, only the sentences are
 * missing.
 *
 * A draft, and only ever a draft. Nothing here saves the note and nothing
 * sends it -- it comes back into the textarea for a person to read, change
 * and then save, because this paragraph goes to a customer over the studio's
 * name and no model gets the last word on that.
 *
 * Every figure is checked before the draft is offered. The model is given a
 * short list of facts and may use no number that is not in it, which is
 * verified rather than requested -- the same rule the WhatsApp assistant
 * follows about money, for the same reason: the one thing worse than no
 * draft is a confident wrong number sent to the client whose month it
 * describes.
 */
class MonthlyReportNoteWriter
{
    public function __construct(private readonly ChatModel $model) {}

    /**
     * @param  array<string, mixed>  $report  as MonthlyReportData::forRange() returns
     *
     * @throws RuntimeException when the model cannot be reached, or wrote a
     *                          figure that is not in the facts
     */
    public function draft(Client $client, Carbon $month, array $report): string
    {
        $facts = self::facts($client, $month, $report);

        $turn = $this->model->reply(self::brief(), [['said' => $facts]], []);

        if ($turn->isRefusal() || blank($turn->text)) {
            throw new RuntimeException('The assistant did not write anything. Try again, or write the note yourself.');
        }

        $draft = trim($turn->text);

        if (self::inventsNumbers($draft, $facts)) {
            throw new RuntimeException(
                'The draft quoted a figure that is not in this month\'s report, so it has been discarded. Try again.'
            );
        }

        return $draft;
    }

    /**
     * What the model is told to write.
     *
     * Short, and firm about the two things that would embarrass the studio:
     * inventing a number, and writing like a marketing email to somebody who
     * is paying for the work being described.
     */
    private static function brief(): string
    {
        return implode("\n", [
            'You write the short note at the top of a monthly report that a photo and video studio in India sends its client.',
            'The client is paying for this work and already knows what their business is. Write to them like a professional who did the work, not like a brochure.',
            '',
            '- Three or four sentences. One paragraph. No headings, no bullet points, no sign-off, no greeting.',
            '- Use only figures given to you below. Never calculate a new one, never round one into a different number, and never mention a figure that is not there.',
            '- Lead with what actually happened, then what is worth doing next month if the numbers suggest something.',
            '- No hype. Do not call anything "amazing", "incredible" or "exciting". If the month was quiet, say so plainly.',
            '- Plain English. No emoji.',
        ]);
    }

    /**
     * The month as a short list of facts, and the only numbers the draft may
     * contain.
     *
     * @param  array<string, mixed>  $report
     */
    private static function facts(Client $client, Carbon $month, array $report): string
    {
        $overview = $report['overview'] ?? [];

        $lines = [
            'Client: '.$client->name,
            'Month: '.$month->format('F Y'),
            'Reach: '.number_format((int) ($overview['reach'] ?? 0)),
            'Views: '.number_format((int) ($overview['views'] ?? 0)),
            'Interactions: '.number_format((int) ($overview['engagement'] ?? 0)),
            'Followers now: '.number_format((int) ($overview['followers'] ?? 0)),
        ];

        $shoots = $report['shoots'] ?? collect();

        $lines[] = 'Shoots this month: '.$shoots->count();

        foreach ($shoots->take(5) as $shoot) {
            $lines[] = '- Shoot: '.$shoot->title.($shoot instanceof Shoot && $shoot->starts_at
                ? ' on '.$shoot->starts_at->format('j M')
                : '');
        }

        $posts = collect($report['content'] ?? []);

        $lines[] = 'Posts published: '.$posts->count();

        foreach ($posts->take(3) as $post) {
            $caption = trim((string) (data_get($post, 'caption') ?? data_get($post, 'title') ?? ''));

            if ($caption !== '') {
                $lines[] = '- Top post: '.mb_substr($caption, 0, 90)
                    .' (reach '.number_format((int) data_get($post, 'reach', 0)).')';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Whether the draft contains a number the facts do not.
     *
     * Compared as bare digits so 1,20,000 and 120000 are one number. Years and
     * small counts under three digits are let through: "three shoots" and
     * "2026" are prose, not claims about performance, and flagging them would
     * discard every usable draft.
     */
    private static function inventsNumbers(string $draft, string $facts): bool
    {
        preg_match_all('/\d[\d,.]*/', $draft, $inDraft);
        preg_match_all('/\d[\d,.]*/', $facts, $inFacts);

        $known = array_map(self::digits(...), $inFacts[0] ?? []);

        foreach ($inDraft[0] ?? [] as $number) {
            $digits = self::digits($number);

            if (mb_strlen($digits) < 3) {
                continue;
            }

            if (! in_array($digits, $known, true)) {
                return true;
            }
        }

        return false;
    }

    private static function digits(string $number): string
    {
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return (string) preg_replace('/\D/', '', $number);
    }
}
