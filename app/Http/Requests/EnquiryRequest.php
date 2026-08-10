<?php

namespace App\Http\Requests;

use App\Models\Enquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnquiryRequest extends FormRequest
{
    /**
     * The enquiry form is public by design - anyone can ask about a project.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Attribution is our bookkeeping, not the sender's problem: a mangled or
     * hand-edited source is dropped rather than failing the submission and
     * costing a real lead.
     */
    protected function prepareForValidation(): void
    {
        $source = $this->input('source');

        if (! is_string($source) || ! isset(Enquiry::SOURCES[$source])) {
            $this->merge(['source' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'project' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],

            // Which page sent them. Carried in a hidden field off the query
            // string, so it is held to the known set rather than trusted.
            'source' => ['nullable', Rule::in(array_keys(Enquiry::SOURCES))],
            'prompted_by' => ['nullable', 'string', 'max:500'],

            // Honeypot: a real person never sees this field, so anything in it
            // is a bot. Named "website" because that is what bots expect to fill.
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => 'Please tell us a little more about the project.',
            'website.prohibited' => 'That submission looked automated. Please try again.',
        ];
    }
}
