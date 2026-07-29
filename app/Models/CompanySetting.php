<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'logo_path',
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
        if (! $this->logo_path) {
            return null;
        }

        $path = public_path($this->logo_path);

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
