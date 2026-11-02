<?php

namespace App\Tools\Invoices;

use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\Invoices\InvoiceWriter;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;

class CreateRecurringInvoice extends Tool
{
    public function name(): string
    {
        return 'create_recurring_invoice';
    }

    public function title(): string
    {
        return 'Set up a recurring invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Set up a standing schedule that generates an invoice for a client every month (or week / '
            .'quarter / year), e.g. a monthly retainer. Give the lines, or from_invoice to copy an existing '
            .'invoice\'s lines, discount and intro. A quantity can be {{published_reels}} etc. to bill what '
            .'was published that month. Generated invoices wait as "pending approval" for a person to '
            .'approve -- nothing reaches the client automatically. Refuses if the client already has an '
            .'active schedule unless allow_another is true; usually update_recurring_invoice is what you want.';
    }

    public function permission(): ?string
    {
        return 'invoices.manage';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Client id or portal name. Optional with from_invoice.'],
            'from_invoice' => ['type' => 'string', 'description' => 'Optional. An invoice number to copy lines, discount and intro from.'],
            'items' => InvoiceToolSupport::itemsSchema(recurring: true),
            'frequency' => ['type' => 'string', 'enum' => array_keys(RecurringInvoice::FREQUENCIES), 'description' => 'Optional; default monthly.'],
            'day_of_month' => ['type' => 'integer', 'description' => 'Optional, 1-31; default 1. Ignored for weekly.'],
            'next_run_on' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD of the first invoice; defaults to the next day_of_month.'],
            'due_days' => ['type' => 'integer', 'description' => 'Optional. Days after the invoice date it falls due.'],
            'label' => ['type' => 'string', 'description' => 'Optional. A name for the schedule, e.g. "Monthly retainer".'],
            'intro_text' => ['type' => 'string', 'description' => 'Optional.'],
            'discount_label' => ['type' => 'string', 'description' => 'Optional.'],
            'discount_amount' => ['type' => 'number', 'description' => 'Optional.'],
            'allow_another' => ['type' => 'boolean', 'description' => 'Only true when the person confirmed a second active schedule for this client.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $source = filled($arguments['from_invoice'] ?? null) ? InvoiceToolSupport::invoice($arguments['from_invoice'])->load('items', 'client') : null;
        $client = filled($arguments['client'] ?? null) ? ClientResolver::resolve($arguments['client']) : $source?->client;

        if (! $client) {
            throw new ToolException('Say which client, or give from_invoice.');
        }
        if ($source && $source->client_id !== $client->id) {
            throw new ToolException("from_invoice {$source->invoice_number} belongs to {$source->client?->name}, not {$client->name}.");
        }

        $items = isset($arguments['items'])
            ? InvoiceToolSupport::items($arguments['items'])
            : ($source ? $source->items->sortBy('sort_order')->map(fn ($i) => ['description' => $i->description, 'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price])->values()->all()
                : throw new ToolException('Give items, or from_invoice to copy them.'));

        $active = RecurringInvoice::where('client_id', $client->id)->where('is_active', true)->pluck('id')->all();
        if ($active && ($arguments['allow_another'] ?? false) !== true) {
            throw new ToolException("{$client->name} already has an active recurring invoice (schedule ".implode(', ', $active).'). '
                .'Use update_recurring_invoice to change it. Only if the person wants a second schedule, call again with allow_another: true.');
        }

        $frequency = $arguments['frequency'] ?? RecurringInvoice::FREQUENCY_MONTHLY;
        if (! isset(RecurringInvoice::FREQUENCIES[$frequency])) {
            throw new ToolException('frequency must be weekly, monthly, quarterly or yearly.');
        }
        $day = (int) ($arguments['day_of_month'] ?? 1);
        if ($day < 1 || $day > 31) {
            throw new ToolException('day_of_month must be 1 to 31.');
        }
        $next = isset($arguments['next_run_on']) ? InvoiceToolSupport::date($arguments['next_run_on'], 'next_run_on') : InvoiceWriter::firstOccurrenceOn($day)->toDateString();
        if ($next < today()->toDateString()) {
            throw new ToolException('next_run_on is in the past -- it would generate back-dated invoices straight away. Use a date from today on.');
        }
        $dueDays = isset($arguments['due_days']) ? (int) $arguments['due_days'] : ($source?->due_date ? (int) $source->invoice_date->diffInDays($source->due_date) : null);
        if ($dueDays !== null && $dueDays < 0) {
            throw new ToolException('due_days cannot be negative.');
        }

        $discount = (isset($arguments['discount_label']) || isset($arguments['discount_amount']))
            ? InvoiceToolSupport::discount($arguments)
            : ['discount_label' => $source?->discount_label, 'discount_amount' => $source?->discount_amount];

        $schedule = RecurringInvoice::create([
            'client_id' => $client->id,
            'label' => $arguments['label'] ?? ($source ? "From {$source->invoice_number}" : null),
            'frequency' => $frequency,
            'day_of_month' => $frequency === RecurringInvoice::FREQUENCY_WEEKLY ? null : $day,
            'due_days' => $dueDays,
            'next_run_on' => $next,
            'intro_text' => $arguments['intro_text'] ?? $source?->intro_text ?? InvoiceWriter::DEFAULT_INTRO,
            ...$discount,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        app(InvoiceWriter::class)->replaceScheduleItems($schedule, $items);

        return ['created' => true, ...InvoiceToolSupport::describeSchedule($schedule->refresh())];
    }
}
