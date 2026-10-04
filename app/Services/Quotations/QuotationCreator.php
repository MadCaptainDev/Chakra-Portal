<?php

namespace App\Services\Quotations;

use App\Models\CompanySetting;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Making a quotation -- one path for the quotation form and the
 * create_quotation MCP tool, so the number sequence, the line totals and
 * the totals arithmetic cannot drift between them.
 */
class QuotationCreator
{
    /**
     * @param  array{client_id: int, is_app_studio?: bool, quotation_date: string, valid_until?: ?string,
     *               intro_text?: ?string, notes?: ?string, discount_label?: ?string, discount_amount?: mixed,
     *               items: list<array{description: string, quantity: mixed, unit_price: mixed}>}  $data
     */
    public function create(array $data, int $userId): Quotation
    {
        return DB::transaction(function () use ($data, $userId) {
            $settings = CompanySetting::current();

            $quotation = Quotation::create([
                'quotation_number' => Quotation::nextQuotationNumber($settings->quotation_prefix),
                'client_id' => $data['client_id'],
                'is_app_studio' => (bool) ($data['is_app_studio'] ?? false),
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'intro_text' => $data['intro_text'] ?? null,
                'notes' => $data['notes'] ?? null,
                'discount_label' => $data['discount_label'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? null,
                'status' => Quotation::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            self::syncItems($quotation, $data['items']);

            $quotation->load('items');
            $quotation->recalculateTotals();
            $quotation->save();

            return $quotation;
        });
    }

    /** @param  array<int, array{description: string, quantity: mixed, unit_price: mixed}>  $items */
    public static function syncItems(Quotation $quotation, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];

            $quotation->items()->create([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($quantity * $unitPrice, 2),
                'sort_order' => $index,
            ]);
        }
    }
}
