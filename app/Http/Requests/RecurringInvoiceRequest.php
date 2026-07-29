<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecurringInvoiceRequest extends FormRequest
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
            'label' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31', 'required_unless:frequency,weekly'],
            'due_days' => ['nullable', 'integer', 'min:0'],
            'next_run_on' => ['required', 'date'],
            'intro_text' => ['nullable', 'string'],
            'discount_label' => ['nullable', 'string', 'max:255', 'required_with:discount_amount'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
