<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceTemplate extends Model
{
    protected $fillable = [
        'name',
        'mode',
        'blocks',
        'html',
        'custom_css',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The template used for invoice PDFs / previews. Creates the classic
     * Chakra layout on first use so existing installs keep working.
     */
    public static function active(): self
    {
        $template = static::query()->where('is_active', true)->latest('id')->first();

        if ($template) {
            return $template;
        }

        return static::query()->create([
            'name' => 'Classic',
            'mode' => 'blocks',
            'blocks' => static::defaultBlocks(),
            'html' => null,
            'custom_css' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Default block layout matching the original invoices.document design.
     *
     * @return list<array{id: string, type: string, enabled: bool, settings: array<string, mixed>}>
     */
    public static function defaultBlocks(): array
    {
        return [
            [
                'id' => 'watermark',
                'type' => 'watermark',
                'enabled' => true,
                'settings' => [],
            ],
            [
                'id' => 'header',
                'type' => 'header',
                'enabled' => true,
                'settings' => ['title' => 'INVOICE'],
            ],
            [
                'id' => 'divider',
                'type' => 'divider',
                'enabled' => true,
                'settings' => [],
            ],
            [
                'id' => 'client',
                'type' => 'client',
                'enabled' => true,
                'settings' => ['label' => 'Quotation to :'],
            ],
            [
                'id' => 'intro',
                'type' => 'intro',
                'enabled' => true,
                'settings' => ['heading' => 'Dear Client'],
            ],
            [
                'id' => 'items',
                'type' => 'items',
                'enabled' => true,
                'settings' => [
                    'items_label' => 'Items',
                    'qty_label' => 'Qty',
                    'rate_label' => 'Rate',
                ],
            ],
            [
                'id' => 'total',
                'type' => 'total',
                'enabled' => true,
                'settings' => ['label' => 'TOTAL :'],
            ],
            [
                'id' => 'signature',
                'type' => 'signature',
                'enabled' => true,
                'settings' => [],
            ],
            [
                'id' => 'footer',
                'type' => 'footer',
                'enabled' => true,
                'settings' => [],
            ],
        ];
    }

    /**
     * Catalog of block types the drag editor can add.
     *
     * @return list<array{type: string, label: string, description: string, fixed: bool}>
     */
    public static function blockCatalog(): array
    {
        return [
            ['type' => 'header', 'label' => 'Header', 'description' => 'Logo and invoice title', 'fixed' => false],
            ['type' => 'divider', 'label' => 'Divider', 'description' => 'Horizontal rule', 'fixed' => false],
            ['type' => 'client', 'label' => 'Client', 'description' => 'Quotation / bill-to block', 'fixed' => false],
            ['type' => 'intro', 'label' => 'Intro', 'description' => 'Dear Client intro text', 'fixed' => false],
            ['type' => 'items', 'label' => 'Items table', 'description' => 'Line items and discount', 'fixed' => false],
            ['type' => 'total', 'label' => 'Total', 'description' => 'Total amount box', 'fixed' => false],
            ['type' => 'text', 'label' => 'Custom text', 'description' => 'Free-form paragraph', 'fixed' => false],
            ['type' => 'spacer', 'label' => 'Spacer', 'description' => 'Vertical gap', 'fixed' => false],
            ['type' => 'watermark', 'label' => 'Watermark', 'description' => 'Background logo mark', 'fixed' => true],
            ['type' => 'signature', 'label' => 'Signature', 'description' => 'Pinned signature block', 'fixed' => true],
            ['type' => 'footer', 'label' => 'Footer', 'description' => 'Pinned thank-you bar', 'fixed' => true],
        ];
    }
}
