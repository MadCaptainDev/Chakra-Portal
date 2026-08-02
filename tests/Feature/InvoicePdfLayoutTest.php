<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the PDF page box itself rather than the Blade output, because the
 * failure modes here are dompdf layout bugs that HTML assertions cannot see:
 * a grey band left under the footer, or a stray blank second page.
 */
class InvoicePdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const A4_WIDTH_PT = 595.28;

    private const A4_HEIGHT_PT = 841.89;

    /**
     * Y positions of horizontal rules in the document, in PDF points from the
     * bottom edge, filtered by rule width so callers can pick out the items
     * table (wide) or the signature underline (narrow).
     *
     * @return array<int, float>  descending
     */
    private function horizontalRules(string $pdf, float $minWidth, float $maxWidth): array
    {
        $content = '';
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream) ?: @gzinflate($stream);
            if ($inflated !== false) {
                $content .= $inflated."\n";
            }
        }

        preg_match_all('/([\d.]+)\s+([\d.]+)\s+m\s+([\d.]+)\s+([\d.]+)\s+l/', $content, $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $m) {
            [$x1, $y1, $x2, $y2] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];

            if (abs($y1 - $y2) > 0.6) {
                continue; // not horizontal
            }

            $width = abs($x2 - $x1);

            if ($width >= $minWidth && $width <= $maxWidth) {
                $rules[] = $y1;
            }
        }

        rsort($rules);

        return $rules;
    }

    private function renderPdf(int $itemCount = 5): string
    {
        // Mirrors a real invoice: five line items, a full address and phone,
        // and the default intro paragraph. A single-item invoice leaves so much
        // slack that it hides pagination regressions -- this shape is what
        // actually sits near the bottom of the page.
        $client = \App\Models\Client::factory()->create([
            'name' => 'SVA Silks and Readymades',
            'address' => 'Madurai Road , Manapparai',
            'phone' => '98989899',
        ]);

        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'intro_text' => 'Professional services rendered as per our agreement. Kindly settle the invoice by the due date indicated.',
        ]);

        $descriptions = array_slice(
            ['Reels', 'SMM', 'Posts', 'Website AMC ( Inc POS )', 'Website Data Entry'],
            0,
            $itemCount
        );

        foreach ($descriptions as $index => $description) {
            $invoice->items()->create([
                'description' => $description,
                'quantity' => 1,
                'unit_price' => 5000,
                'line_total' => 5000,
                'sort_order' => $index,
            ]);
        }

        $invoice->load('client', 'items');

        return Pdf::loadView('invoices.document', [
            'invoice' => $invoice,
            'settings' => CompanySetting::current(),
        ])->setPaper('a4')->output();
    }

    /**
     * Every filled rectangle in the document, as
     * ['rgb' => [r, g, b], 'x' => , 'y' => , 'w' => , 'h' => ].
     *
     * @return array<int, array<string, mixed>>
     */
    private function filledRectangles(string $pdf): array
    {
        $content = '';

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream) ?: @gzinflate($stream);

            if ($inflated !== false) {
                $content .= $inflated."\n";
            }
        }

        $this->assertNotSame('', $content, 'Could not inflate the PDF content stream.');

        $rectangles = [];
        $colour = null;

        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);

            if (preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+rg$/', $line, $c)) {
                $colour = [(float) $c[1], (float) $c[2], (float) $c[3]];

                continue;
            }

            // dompdf puts the paint operator on the same line: "x y w h re f"
            if ($colour && preg_match('/^(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+re\b/', $line, $r)) {
                $rectangles[] = [
                    'rgb' => $colour,
                    'x' => (float) $r[1],
                    'y' => (float) $r[2],
                    'w' => (float) $r[3],
                    'h' => (float) $r[4],
                ];
            }
        }

        return $rectangles;
    }

    private function isColour(array $rgb, array $target): bool
    {
        foreach ($target as $i => $channel) {
            if (abs($rgb[$i] - $channel) > 0.004) {
                return false;
            }
        }

        return true;
    }

    public function test_client_phone_is_shown_under_the_address_when_present(): void
    {
        $client = \App\Models\Client::factory()->create([
            'name' => 'Thor Gym',
            'address' => 'Manapparai, Tamil Nadu',
            'phone' => '9876543210',
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);
        $invoice->load('client', 'items');

        $html = view('invoices.document', [
            'invoice' => $invoice,
            'settings' => CompanySetting::current(),
        ])->render();

        $this->assertStringContainsString('9876543210', $html);

        // Order matters: name, then address, then phone.
        $this->assertGreaterThan(
            strpos($html, 'Manapparai, Tamil Nadu'),
            strpos($html, '9876543210'),
            'The phone number should render after the address, not before it.'
        );
    }

    public function test_client_phone_block_is_omitted_when_blank(): void
    {
        $client = \App\Models\Client::factory()->create([
            'address' => 'Madurai Road',
            'phone' => null,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);
        $invoice->load('client', 'items');

        $html = view('invoices.document', [
            'invoice' => $invoice,
            'settings' => CompanySetting::current(),
        ])->render();

        // Match the rendered element, not the bare class name -- the CSS rule
        // `.quotation-to .client-phone` is in the stylesheet on every invoice.
        $this->assertStringNotContainsString('<div class="client-phone">', $html);
    }

    public function test_invoice_pdf_is_a_single_a4_page(): void
    {
        $pdf = $this->renderPdf();

        preg_match_all('/\/Type\s*\/Page[^s]/', $pdf, $pages);
        $this->assertCount(1, $pages[0], 'The invoice should fit on exactly one page.');

        preg_match('/\/MediaBox\s*\[([^\]]+)\]/', $pdf, $box);
        [, , $width, $height] = preg_split('/\s+/', trim($box[1]));
        $this->assertEqualsWithDelta(self::A4_WIDTH_PT, (float) $width, 0.1);
        $this->assertEqualsWithDelta(self::A4_HEIGHT_PT, (float) $height, 0.1);
    }

    public function test_no_grey_band_is_left_below_the_footer(): void
    {
        $rectangles = $this->filledRectangles($this->renderPdf());

        foreach ($rectangles as $rectangle) {
            $this->assertFalse(
                $this->isColour($rectangle['rgb'], [0.910, 0.910, 0.910]),
                'The old #e8e8e8 page background is back; it renders as a grey band under the footer.'
            );
        }
    }

    public function test_footer_bar_sits_flush_on_the_bottom_edge(): void
    {
        $rectangles = $this->filledRectangles($this->renderPdf());

        // PDF origin is bottom-left, so the footer must start at y = 0.
        $footer = collect($rectangles)->first(fn ($rectangle) => $this->isColour($rectangle['rgb'], [0.404, 0.737, 0.831])
            && $rectangle['w'] > self::A4_WIDTH_PT - 1
            && $rectangle['y'] < 0.5);

        $this->assertNotNull($footer, 'No full-width teal footer bar found at the bottom edge of the page.');
        $this->assertGreaterThan(30, $footer['h'], 'The footer bar looks too short to be the footer.');
    }

    public function test_signature_sits_at_the_bottom_regardless_of_item_count(): void
    {
        // Narrow rules only: the signature underline, not the table borders.
        $short = $this->horizontalRules($this->renderPdf(1), 40, 200);
        $long = $this->horizontalRules($this->renderPdf(5), 40, 200);

        $this->assertNotEmpty($short, 'No signature underline found.');
        $this->assertNotEmpty($long, 'No signature underline found.');

        $shortY = end($short);
        $longY = end($long);

        $this->assertEqualsWithDelta($shortY, $longY, 1.0,
            'The signature must stay pinned to the foot of the page instead of following the last line item.');

        // ...and it must sit above the footer bar (50.1pt tall), not on it.
        $this->assertGreaterThan(55, $shortY, 'The signature is overlapping the footer bar.');
        $this->assertLessThan(160, $shortY, 'The signature has drifted up away from the bottom of the page.');
    }

    public function test_total_box_is_joined_to_the_items_table(): void
    {
        $rectangles = $this->filledRectangles($this->renderPdf());

        // Narrow teal boxes on the right are the table's header cell and the
        // total box; the total is the lower of the two. (The footer bar is
        // full width, so it's already excluded.)
        $total = collect($rectangles)
            ->filter(fn ($r) => $this->isColour($r['rgb'], [0.404, 0.737, 0.831]) && $r['w'] < 200 && $r['x'] > 300)
            ->sortBy('y')
            ->first();

        $this->assertNotNull($total, 'No total box found.');

        // Bottom-most wide rule = the last row separator of the items table.
        $tableRules = $this->horizontalRules($this->renderPdf(), 300, 450);
        $this->assertNotEmpty($tableRules);
        $tableBottom = end($tableRules);

        $totalTop = $total['y'] + $total['h'];

        $this->assertEqualsWithDelta($tableBottom, $totalTop, 2.0,
            'The total box should butt directly against the bottom of the items table, with no gap.');
    }

    public function test_page_background_covers_the_whole_sheet(): void
    {
        $rectangles = $this->filledRectangles($this->renderPdf());

        $white = collect($rectangles)->first(fn ($rectangle) => $this->isColour($rectangle['rgb'], [1.0, 1.0, 1.0])
            && $rectangle['x'] < 0.5
            && $rectangle['y'] < 0.5
            && $rectangle['w'] > self::A4_WIDTH_PT - 1
            && $rectangle['h'] > self::A4_HEIGHT_PT - 1);

        $this->assertNotNull($white, 'The sheet is not filled white edge to edge, so a coloured gap can show.');
    }
}
