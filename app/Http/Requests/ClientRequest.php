<?php

namespace App\Http\Requests;

use App\Models\Client;
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

            // sometimes, not required: quickStore()/quickUpdate() (the
            // invoice modal's lightweight client picker) never offer this
            // field at all -- see prepareForValidation() for what an
            // absent value resolves to on each of the four entry points.
            'client_type' => ['sometimes', Rule::in(array_keys(Client::CLIENT_TYPES))],
            'service_note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // The real form always sends is_active and client_type (is_active
        // via a hidden 0 ahead of the checkbox, same pattern as
        // salaries/_form.blade.php; client_type via its own required
        // <select>) -- both are genuinely present on every submission from
        // clients/_form.blade.php, ticked/selected or not.
        //
        // quickStore()/quickUpdate() (the invoice modal's lightweight
        // client picker) never render either field, so they are genuinely
        // absent there. Falling back to a fixed default unconditionally
        // would silently reactivate an inactive client, or reset their
        // type to Regular, the moment their name gets a quick edit from
        // that modal -- falling back to the bound client's own current
        // value instead makes an absent field a no-op on update, and the
        // sensible default only for a brand-new client (no bound client to
        // read from).
        $client = $this->route('client');

        $this->merge([
            'whatsapp_portal_enabled' => $this->boolean('whatsapp_portal_enabled'),
            'is_active' => $this->has('is_active')
                ? $this->boolean('is_active')
                : ($client?->is_active ?? true),
            'client_type' => $this->filled('client_type')
                ? $this->input('client_type')
                : ($client?->client_type ?? Client::CLIENT_TYPE_REGULAR),
        ]);
    }
}
