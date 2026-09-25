<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProposalRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'valid_until' => ['nullable', 'date'],
            /*
             * The whole section editor posts as one JSON string -- nested
             * blocks inside sections inside a form would otherwise be three
             * levels of input names to keep in sync with the Alpine state.
             * Its shape is not validated field by field here:
             * ProposalBlocks::fromForm() normalises every value it keeps and
             * drops anything it does not recognise.
             */
            'sections_json' => ['required', 'string', 'max:500000'],
            'cover_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'remove_cover_logo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! is_array(json_decode((string) $this->input('sections_json'), true))) {
                    $validator->errors()->add('sections_json', 'The sections could not be read. Reload the page and try again.');
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function sectionsForm(): array
    {
        return (array) json_decode((string) $this->input('sections_json'), true);
    }
}
