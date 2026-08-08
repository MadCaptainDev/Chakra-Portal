<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->isPendingApproval()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Approve this invoice before recording a payment.');
        }

        // Cap at what is still owed so a stray zero cannot be recorded and
        // then silently swallowed by balanceDue()'s max(0, ...).
        $balance = $invoice->balanceDue();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.($balance + Invoice::AMOUNT_EPSILON)],
            'paid_on' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.max' => 'Payment exceeds the balance due of '.number_format($balance, 2).'.',
        ]);

        $invoice->payments()->create([
            ...$validated,
            'recorded_by' => $request->user()->id,
        ]);

        return redirect()->route('invoices.show', $invoice)->with('status', 'Payment recorded.');
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $invoice = $payment->invoice;
        $payment->delete();

        return redirect()->route('invoices.show', $invoice)->with('status', 'Payment removed.');
    }
}
