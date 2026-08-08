<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Salary and EMI base amounts are fixed after create. Changing them requires
 * an explicit unlock_amount flag from the UI confirmation flow.
 */
trait LocksExpenseAmount
{
    /**
     * Amount is required on create, or when the user has unlocked it for edit.
     * Otherwise the submitted amount is ignored and the existing value kept.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function withLockedAmountRules(Request $request, array $rules, bool $isUpdate): array
    {
        if ($isUpdate && ! $request->boolean('unlock_amount')) {
            $rules['amount'] = ['nullable', 'numeric', 'min:0'];
        } else {
            $rules['amount'] = ['required', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * Drop amount from validated data when the edit was not unlocked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withoutLockedAmount(Request $request, array $data, bool $isUpdate): array
    {
        if ($isUpdate && ! $request->boolean('unlock_amount')) {
            unset($data['amount']);
        }

        return $data;
    }

    /**
     * When unlocking, require a confirmation checkbox in addition to unlock_amount.
     */
    protected function confirmAmountUnlock(Request $request, Validator $validator, bool $isUpdate): void
    {
        if (! $isUpdate || ! $request->boolean('unlock_amount')) {
            return;
        }

        if (! $request->boolean('confirm_amount_change')) {
            $validator->errors()->add(
                'confirm_amount_change',
                'Confirm that you want to change the locked amount.'
            );
        }
    }
}
