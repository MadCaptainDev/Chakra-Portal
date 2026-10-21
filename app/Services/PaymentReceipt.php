<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\WhatsappServiceWindow;
use RuntimeException;

/**
 * "We've received your payment" to the client on WhatsApp, sent when a
 * payment is recorded against their invoice.
 *
 * Same two routes as every client message: a plain message when they have
 * written to us in the last 24 hours, the approved payment_received_v1
 * template otherwise (a plain message outside that window is accepted and
 * then silently dropped -- see WhatsappServiceWindow). Logged on the invoice
 * like every other send, so it shows in the invoice's WhatsApp history.
 */
class PaymentReceipt
{
    public function __construct(private DocumentWhatsappNotifier $notifier) {}

    /**
     * @return string the number it went to
     *
     * @throws RuntimeException when there is no number or WhatsApp refuses it
     */
    public function send(Payment $payment, ?int $sentByUserId): string
    {
        $invoice = $payment->invoice()->with('client', 'payments')->firstOrFail();
        $phone = $invoice->client?->phone;

        if (blank($phone)) {
            throw new RuntimeException('the client has no phone number on file');
        }

        [$name, $amount, $number, $balance] = self::parameters($payment, $invoice);

        if (WhatsappServiceWindow::isOpen($phone)) {
            $this->notifier->sendText($invoice, $phone, self::messageText($payment, $invoice), $sentByUserId);
        } else {
            $this->notifier->send(
                document: $invoice,
                phone: $phone,
                template: Invoice::WHATSAPP_TEMPLATE_PAYMENT_RECEIVED,
                bodyParameters: [$name, $amount, $number, $balance],
                buttonUrlParameter: null,
                sentByUserId: $sentByUserId,
            );
        }

        return $phone;
    }

    /** The plain-message version, for inside the 24-hour window. */
    public static function messageText(Payment $payment, Invoice $invoice): string
    {
        [$name, $amount, $number, $balance] = self::parameters($payment, $invoice);
        $tail = $invoice->balanceDue() > Invoice::AMOUNT_EPSILON
            ? "Balance remaining: Rs. {$balance}."
            : 'This invoice is now fully paid.';

        return "Hi {$name}, thank you! We have received your payment of Rs. {$amount} for invoice {$number}. {$tail} - Team Chakra";
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private static function parameters(Payment $payment, Invoice $invoice): array
    {
        return [
            $invoice->client?->name ?? 'there',
            number_format((float) $payment->amount, 2),
            (string) $invoice->invoice_number,
            number_format(max(0, $invoice->balanceDue()), 2),
        ];
    }
}
