<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentReceipt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice, PaymentReceipt $receipts): RedirectResponse
    {
        if ($invoice->isPendingApproval()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Approve this invoice before recording a payment.');
        }

        // Cap at what is still owed: without a ceiling an over-payment drives
        // balanceDue() negative and the invoice still reads as paid. The rule
        // carries the ceiling so the message lands on the amount field itself.
        $balance = $invoice->balanceDue();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.($balance + Invoice::AMOUNT_EPSILON)],
            'paid_on' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.max' => 'Payment exceeds the balance due of '.number_format($balance, 2).'.',
        ]);

        $payment = $invoice->payments()->create([
            ...$validated,
            'recorded_by' => $request->user()->id,
        ]);

        // The client's thank-you, unless the box was unticked. The payment is
        // saved either way: a WhatsApp failure is reported, never a reason to
        // lose the record of money received.
        if (! $request->boolean('notify_client')) {
            return redirect()->route('invoices.show', $invoice)->with('status', 'Payment recorded.');
        }

        try {
            $phone = $receipts->send($payment, $request->user()->id);
        } catch (RuntimeException $e) {
            return redirect()->route('invoices.show', $invoice)
                ->with('status', 'Payment recorded.')
                ->with('error', 'The WhatsApp receipt was not sent: '.$e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Payment recorded, and a thank-you sent to {$phone} on WhatsApp.");
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $invoice = $payment->invoice;
        $payment->delete();

        return redirect()->route('invoices.show', $invoice)->with('status', 'Payment removed.');
    }
}
