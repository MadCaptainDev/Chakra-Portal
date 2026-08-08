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

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Without a ceiling, an over-payment drives balanceDue() negative and
        // the invoice still reads as paid. Refuse it and name the balance so
        // the correction is obvious.
        $balance = $invoice->balanceDue();

        if ((float) $validated['amount'] > $balance + 0.001) {
            return redirect()->route('invoices.show', $invoice)->with(
                'error',
                'That is more than the outstanding balance of '.number_format($balance, 2).'. Record '.number_format($balance, 2).' or less, or edit the invoice first.'
            );
        }

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
