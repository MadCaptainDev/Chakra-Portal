<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notion_venture' => ['nullable', 'string', 'max:255'],

            // The logo is handled by the controller, not mass-assigned: the
            // validated array must not carry the UploadedFile into create().
            //
            // Raster only. Laravel's "image" rule admits SVG, and an SVG is a
            // document that can carry script -- served back from our own origin
            // that is stored XSS, so the extensions are named explicitly.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }
}
