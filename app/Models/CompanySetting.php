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
     * Which logo one specific invoice should render with: App Studio's own
     * mark for an invoice tagged with a saas_product_id, the ordinary
     * company logo for everything else -- and the ordinary logo again if
     * App Studio's has never been uploaded, so an invoice is never left
     * with no logo at all just because that upload has not happened yet.
     */
    public function logoDataUriFor(Invoice $invoice): ?string
    {
        if ($invoice->saas_product_id && $this->app_studio_logo_data_uri) {
            return $this->app_studio_logo_data_uri;
        }

        return $this->logo_data_uri;
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
