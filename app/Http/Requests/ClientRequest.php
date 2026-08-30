<?php

namespace App\Http\Requests;

use App\Models\TaxonomyTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            /*
             * The sector. Fillable on the model since the taxonomy landed, but
             * with no rule here and no field on the form it was unsettable
             * through the UI. The brand brief now writes to it from the client
             * side, so staff need to be able to see and correct it -- a field
             * only the client can change is worse than one nobody can.
             *
             * Pinned to its own list, like ScriptRequest's pickers: without the
             * type constraint a tag id would validate and the client would show
             * a tag where the sector belongs.
             */
            'industry_id' => ['nullable', Rule::exists('taxonomy_terms', 'id')->where('type', TaxonomyTerm::TYPE_INDUSTRY)],

            // The logo is handled by the controller, not mass-assigned: the
            // validated array must not carry the UploadedFile into create().
            //
            // Raster only. Laravel's "image" rule admits SVG, and an SVG is a
            // document that can carry script -- served back from our own origin
            // that is stored XSS, so the extensions are named explicitly.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'whatsapp_portal_enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'whatsapp_portal_enabled' => $this->boolean('whatsapp_portal_enabled'),
        ]);
    }
}
