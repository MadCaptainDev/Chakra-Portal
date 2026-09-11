<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotationRequest extends FormRequest
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
            'client_id' => ['required', 'exists:clients,id'],
            // Which side of Chakra this is for -- just a label, no product
            // picker (that only happens once this becomes an invoice); read
            // via $request->boolean() in the controller so an unchecked box
            // (absent from the payload) is not a validation failure.
            'is_app_studio' => ['sometimes', 'boolean'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'intro_text' => ['nullable', 'string'],
            // Free-form bullet points shown after the line items -- scope
            // notes, timeline, payment terms, whatever the quote needs to
            // spell out. One point per line; see QuotationController.
            'notes' => ['nullable', 'string'],
            'discount_label' => ['nullable', 'string', 'max:255', 'required_with:discount_amount'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
