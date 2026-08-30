<?php

namespace App\Rules;

use App\Support\InvoiceQuantityVariable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InvoiceItemQuantity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (InvoiceQuantityVariable::accepts($value)) {
            return;
        }

        $fail('Enter a quantity of at least 0.01, or pick a published-content variable.');
    }
}
