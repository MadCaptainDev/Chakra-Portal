<?php

namespace App\Http\Requests;

use App\Support\BrandBrief;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a brief submission, generated from the question catalogue.
 *
 * One class for three callers now: the partial save, the autosave and the final
 * submit. The difference is only whether the required questions are enforced,
 * and that is decided by the ROUTE NAME -- not by a posted field. A posted
 * "intent" flag is one crafted request away from skipping every required
 * question; the route is the thing the middleware has already vouched for.
 */
class ClientBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware owns access; this only shapes the input.
        return true;
    }

    /** Whether this request is the final submit rather than a save. */
    public function isSubmitting(): bool
    {
        // Both doors onto the same form: the signed-in portal and the public
        // one-time link. Named explicitly rather than matched with a wildcard,
        // so a route added later has to opt in to skipping nothing.
        return $this->routeIs('client.brief.submit') || $this->routeIs('brief.public.submit');
    }

    /**
     * Anything not in the catalogue is dropped here, before validation.
     *
     * Dropped rather than rejected, on the same reasoning as EnquiryRequest's
     * treatment of `source`: a stray key is our problem, not the client's, and
     * failing their whole submission over one is how a ten-minute form becomes
     * an email instead. Empty strings, empty arrays and empty contact groups
     * are normalised to null so that clearing a field reads as unanswered
     * everywhere.
     */
    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');
        $clean = [];

        foreach (is_array($answers) ? $answers : [] as $key => $value) {
            if (! is_string($key) || ! BrandBrief::isKnownKey($key)) {
                continue;
            }

            $type = BrandBrief::question($key)['type'] ?? null;

            if ($type === BrandBrief::TYPE_CONTACT) {
                $clean[$key] = $this->cleanContact($value);

                continue;
            }

            if (is_array($value)) {
                $value = array_values(array_filter(
                    array_map(fn ($one) => is_scalar($one) ? trim((string) $one) : null, $value),
                    fn ($one) => $one !== null && $one !== ''
                ));

                $clean[$key] = $value === [] ? null : $value;

                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : null;
            $clean[$key] = ($value === null || $value === '') ? null : $value;
        }

        $this->merge(['answers' => $clean]);
    }

    /**
     * A contact group is kept only if somebody is actually named in it. Three
     * blank boxes are not an answer, and storing them would make the review
     * screen claim the question was done.
     *
     * @return array<string, string>|null
     */
    private function cleanContact(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $contact = [];

        foreach (array_keys(BrandBrief::CONTACT_FIELDS) as $field) {
            $one = $value[$field] ?? null;
            $one = is_scalar($one) ? trim((string) $one) : '';

            if ($one !== '') {
                $contact[$field] = $one;
            }
        }

        return $contact === [] ? null : $contact;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['answers' => ['array']];
        $submitting = $this->isSubmitting();
        $answers = $this->input('answers', []);

        foreach (BrandBrief::questions() as $key => $question) {
            $field = "answers.{$key}";

            /*
             * Required only on the way in for good, and only when the question
             * is actually being asked. A conditional question the client never
             * saw must never block their submit -- and a partial save must be
             * able to hold three answers and nothing else, or "save and come
             * back" is a promise the form cannot keep.
             */
            $required = $submitting
                && ($question['required'] ?? false)
                && BrandBrief::isVisible($key, is_array($answers) ? $answers : []);

            $presence = $required ? 'required' : 'nullable';
            $options = BrandBrief::optionsFor($key);

            switch ($question['type']) {
                case BrandBrief::TYPE_CHIPS:
                case BrandBrief::TYPE_CHECKS:
                    if ($question['multi'] ?? false) {
                        $rules[$field] = [$presence, 'array', 'max:'.count($options)];
                        $rules[$field.'.*'] = [Rule::in($options)];
                    } else {
                        $rules[$field] = [$presence, Rule::in($options)];
                    }
                    break;

                case BrandBrief::TYPE_URLS:
                    $rules[$field] = [$presence, 'array', 'max:'.BrandBrief::MAX_URLS];
                    // url:http,https rather than plain url: without it "javascript:"
                    // and "data:" both pass, and these get clicked by staff.
                    $rules[$field.'.*'] = ['string', 'url:http,https', 'max:255'];
                    break;

                case BrandBrief::TYPE_CONTACT:
                    $rules[$field] = [$presence, 'array'];
                    $rules[$field.'.name'] = [$required ? 'required' : 'nullable', 'string', 'max:120'];
                    $rules[$field.'.phone'] = ['nullable', 'string', 'max:40'];
                    $rules[$field.'.email'] = ['nullable', 'email', 'max:190'];
                    break;

                default:
                    $rules[$field] = [
                        $presence,
                        'string',
                        'max:'.($question['max'] ?? BrandBrief::DEFAULT_MAX),
                    ];
            }
        }

        // The free text beside a chosen "Other". Never required: the chip
        // itself carries the answer, and this only sharpens it.
        foreach (BrandBrief::questions() as $key => $question) {
            if ($question['other'] ?? false) {
                $rules["answers.{$key}_other"] = ['nullable', 'string', 'max:255'];
            }
        }

        return $rules;
    }

    /**
     * Errors name the question the client read, not "answers.perception".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (BrandBrief::questions() as $key => $question) {
            $label = mb_strtolower(rtrim($question['label'], '?.'));

            $attributes["answers.{$key}"] = $label;
            $attributes["answers.{$key}.name"] = 'contact name';
            $attributes["answers.{$key}.email"] = 'contact email';
            $attributes["answers.{$key}.phone"] = 'contact phone';
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
