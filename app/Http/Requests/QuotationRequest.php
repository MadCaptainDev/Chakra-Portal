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
    public function messages(): array
    {
        return [
            'client_id.required_without' => 'Pick a client, or type the name of who it is for.',
            'recipient_name.required_without' => 'Pick a client, or type the name of who it is for.',
            'recipient_phone.regex' => 'That doesn\'t look like a phone number.',
        ];
    }

    public function rules(): array
    {
        return [
            // A client from the portal, or anyone: a name and a WhatsApp number.
            'client_id' => ['nullable', 'required_without:recipient_name', 'exists:clients,id'],
            'recipient_name' => ['nullable', 'required_without:client_id', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'recipient_address' => ['nullable', 'string', 'max:255'],
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
