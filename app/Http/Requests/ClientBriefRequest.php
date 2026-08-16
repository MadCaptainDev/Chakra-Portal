<?php

namespace App\Http\Requests;

use App\Support\BrandBrief;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a brief submission, generated from the question catalogue.
 *
 * One class for both the partial save and the final submit. The difference
 * between them is whether the required questions are enforced, and that is
 * decided by the ROUTE NAME -- not by a posted field. A posted "intent" flag
 * is one crafted request away from skipping every required question; the route
 * is the thing the middleware has already vouched for.
 */
class ClientBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware owns access; this only shapes the input.
        return true;
    }

    /** Whether this request is the final submit rather than a partial save. */
    public function isSubmitting(): bool
    {
        return $this->routeIs('client.brief.submit');
    }

    /**
     * Anything not in the catalogue is dropped here, before validation.
     *
     * Dropped rather than rejected, on the same reasoning as EnquiryRequest's
     * treatment of `source`: a stray key is our problem, not the client's, and
     * failing their whole submission over one is how a ten-minute form becomes
     * an email instead. Empty strings and empty arrays are normalised to null
     * so that clearing a field reads as unanswered everywhere.
     */
    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');
        $clean = [];

        foreach (is_array($answers) ? $answers : [] as $key => $value) {
            if (! is_string($key) || ! BrandBrief::isKnownKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $value = array_values(array_filter($value, fn ($one) => $one !== null && $one !== ''));
                $clean[$key] = $value === [] ? null : $value;

                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : null;
            $clean[$key] = ($value === null || $value === '') ? null : $value;
        }

        $this->merge(['answers' => $clean]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['answers' => ['array']];
        $submitting = $this->isSubmitting();

        foreach (BrandBrief::QUESTIONS as $key => $question) {
            $field = "answers.{$key}";

            // Required only on the way in for good. A partial save must be
            // able to hold three answers and nothing else, or "save and come
            // back" is a promise the form cannot keep.
            $presence = ($submitting && $question['required']) ? 'required' : 'nullable';

            $taxonomy = BrandBrief::taxonomyFor($key);
            $options = BrandBrief::optionsFor($key);

            switch ($question['type']) {
                case BrandBrief::TYPE_MULTISELECT:
                    $rules[$field] = [$presence, 'array', 'max:'.($question['limit'] ?? 10)];
                    $rules[$field.'.*'] = $taxonomy
                        ? [$this->term($taxonomy)]
                        : [Rule::in(array_keys($options))];
                    break;

                case BrandBrief::TYPE_SELECT:
                    // Pinned to its own list. Without the type constraint a tag
                    // id posted into objective_id would validate, and the brief
                    // would show a tag where the objective should be.
                    $rules[$field] = [$presence, $this->term($taxonomy)];
                    break;

                case BrandBrief::TYPE_CHOICE:
                    $rules[$field] = [$presence, Rule::in(array_keys($options))];
                    break;

                case BrandBrief::TYPE_URL:
                    $rules[$field] = [$presence, 'string', 'url', 'max:255'];
                    break;

                case BrandBrief::TYPE_NUMBER:
                    $rules[$field] = [$presence, 'numeric'];
                    break;

                default:
                    $rules[$field] = [
                        $presence,
                        'string',
                        'max:'.($question['max'] ?? BrandBrief::DEFAULT_MAX),
                    ];
            }
        }

        return $rules;
    }

    private function term(string $type): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('taxonomy_terms', 'id')->where('type', $type);
    }

    /**
     * Errors name the question the client read, not "answers.usp".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (BrandBrief::QUESTIONS as $key => $question) {
            $attributes["answers.{$key}"] = mb_strtolower(rtrim($question['label'], '?'));
        }

        return $attributes;
    }

    /**
     * The answers, keyed by question key, already cleaned.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return $this->validated()['answers'] ?? [];
    }
}
