<?php

namespace App\Http\Requests;

use App\Models\Script;
use App\Models\TaxonomyTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware owns access; this only shapes the input.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'status' => ['required', Rule::in(array_keys(Script::STATUSES))],
            'priority' => ['required', Rule::in(array_keys(Script::PRIORITIES))],

            // Only a real login can be named, and only one that still exists.
            'writer_id' => ['nullable', Rule::exists('users', 'id')],
            'editor_id' => ['nullable', Rule::exists('users', 'id')],

            'campaign' => ['nullable', 'string', 'max:255'],

            /*
             * Each picker is pinned to its own list. Without the ->where on
             * type, a tag id posted into script_type_id would validate and the
             * script would silently show a tag where its type should be.
             */
            'platform_id' => ['nullable', $this->term(TaxonomyTerm::TYPE_PLATFORM)],
            'script_type_id' => ['nullable', $this->term(TaxonomyTerm::TYPE_SCRIPT_TYPE)],
            'language_id' => ['nullable', $this->term(TaxonomyTerm::TYPE_LANGUAGE)],

            'target_seconds' => ['nullable', 'integer', 'min:1', 'max:36000'],
            'due_on' => ['nullable', 'date'],
        ];
    }

    private function term(string $type): Exists
    {
        return Rule::exists('taxonomy_terms', 'id')->where('type', $type);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => 'client',
            'writer_id' => 'writer',
            'editor_id' => 'editor',
            'script_type_id' => 'script type',
            'platform_id' => 'platform',
            'language_id' => 'language',
            'target_seconds' => 'target duration',
            'due_on' => 'deadline',
        ];
    }
}
