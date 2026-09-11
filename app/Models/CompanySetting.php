<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'logo_path',
        'app_studio_logo_path',
        'address',
        'signature_name',
        'signature_title',
        'invoice_prefix',
        'quotation_prefix',
        'footer_text',
        'notification_email',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'company_name' => 'Chakra Productions',
            'logo_path' => 'images/chakra-logo.png',
            'signature_name' => 'Annamalai Sivakumar',
            'signature_title' => 'CEO',
            'invoice_prefix' => 'CP-',
            'quotation_prefix' => 'QT-',
            'footer_text' => 'ThankYou For Your Buisness With Us !',
        ]);
    }

    /**
     * Logo as a base64 data URI so it renders identically in the browser
     * preview and in the dompdf-generated PDF, without relying on a
     * reachable URL or filesystem path in either context.
     */
    public function getLogoDataUriAttribute(): ?string
    {
        return $this->dataUriFor($this->logo_path);
    }

    /**
     * The separate mark for Chakra App Studio invoices, or null if one has
     * never been uploaded -- see logoDataUriFor() for the fallback an
     * invoice actually renders with.
     */
    public function getAppStudioLogoDataUriAttribute(): ?string
    {
        return $this->dataUriFor($this->app_studio_logo_path);
    }

    /**
     * Which logo path one specific invoice should render with: App Studio's
     * own mark for an invoice tagged with a saas_product_id, the ordinary
     * company logo for everything else -- and the ordinary logo again if
     * App Studio's has never been uploaded, so an invoice is never left
     * with no logo at all just because that upload has not happened yet.
     * The raw path, not the data URI -- logoBoxStyle() needs the file on
     * disk to measure it, and logoDataUriFor() below just wraps this.
     */
    public function logoPathFor(Invoice $invoice): ?string
    {
        if ($invoice->saas_product_id && $this->app_studio_logo_path && is_file(public_path($this->app_studio_logo_path))) {
            return $this->app_studio_logo_path;
        }

        return $this->logo_path;
    }

    public function logoDataUriFor(Invoice $invoice): ?string
    {
        return $this->dataUriFor($this->logoPathFor($invoice));
    }

    /**
     * Inline width/height for a logo image, preserving its own aspect ratio
     * but capped so a wide wordmark (App Studio's own mark is 730x129 --
     * 5.66:1, versus the production mark's roughly square 1.9:1) can never
     * grow wider than the invoice/quotation heading has room for. The old
     * CSS only capped height (`height: 18mm`), so at that height App
     * Studio's mark rendered ~102mm wide -- half the page -- and ran into
     * the heading. Falls back to a height-only cap if the file can't be
     * measured, which just reproduces the old (safe for a normal logo)
     * behaviour rather than breaking the layout outright.
     */
    public function logoBoxStyle(?string $relativePath, float $maxWidthMm = 46, float $maxHeightMm = 16): string
    {
        $fallback = sprintf('max-height: %.1fmm; max-width: %.1fmm;', $maxHeightMm, $maxWidthMm);

        if (! $relativePath) {
            return $fallback;
        }

        $path = public_path($relativePath);

        if (! is_file($path)) {
            return $fallback;
        }

        $size = @getimagesize($path);

        if (! $size || $size[0] <= 0 || $size[1] <= 0) {
            return $fallback;
        }

        $ratio = $size[0] / $size[1];
        $boxRatio = $maxWidthMm / $maxHeightMm;

        if ($ratio > $boxRatio) {
            // Wider than the box (a wordmark like App Studio's) -- width is
            // the binding constraint, height shrinks to match.
            $width = $maxWidthMm;
            $height = $maxWidthMm / $ratio;
        } else {
            $height = $maxHeightMm;
            $width = $maxHeightMm * $ratio;
        }

        return sprintf('width: %.1fmm; height: %.1fmm;', $width, $height);
    }

    private function dataUriFor(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }

        $path = public_path($relativePath);

        if (! is_file($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return "data:{$mime};base64,".base64_encode(file_get_contents($path));
    }
}
