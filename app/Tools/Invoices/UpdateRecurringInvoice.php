<?php

namespace App\Tools\Invoices;

use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\Invoices\InvoiceWriter;
use App\Tools\Tool;
use App\Tools\ToolException;

class UpdateRecurringInvoice extends Tool
{
    public function name(): string
    {
        return 'update_recurring_invoice';
    }

    public function title(): string
    {
        return 'Edit or pause a recurring invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Change a recurring invoice schedule: its lines, discount, frequency, day of month, next date, '
            .'due days, label or intro -- or pause / resume it with active. Only what you pass changes; '
            .'"items" REPLACES every line, so call list_recurring_invoices first and send the full list. '
            .'Affects invoices generated from now on, never ones already made (edit those with update_invoice).';
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
            'schedule_id' => ['type' => 'integer', 'description' => 'From list_recurring_invoices.'],
            'active' => ['type' => 'boolean', 'description' => 'Optional. false pauses it, true resumes it.'],
            'items' => InvoiceToolSupport::itemsSchema(recurring: true),
            'frequency' => ['type' => 'string', 'enum' => array_keys(RecurringInvoice::FREQUENCIES)],
            'day_of_month' => ['type' => 'integer', 'description' => 'Optional, 1-31.'],
            'next_run_on' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD of the next invoice, today or later.'],
            'due_days' => ['type' => 'integer', 'description' => 'Optional. -1 clears it.'],
            'label' => ['type' => 'string'],
            'intro_text' => ['type' => 'string'],
            'discount_label' => ['type' => 'string', 'description' => 'Optional. "" with discount_amount 0 removes the discount.'],
            'discount_amount' => ['type' => 'number'],
        ], ['schedule_id']);
    }

    public function handle(array $arguments, User $user): array
    {
        $schedule = InvoiceToolSupport::schedule($arguments['schedule_id'] ?? 0);
        $before = InvoiceToolSupport::describeSchedule($schedule);
        $data = [];

        if (array_key_exists('active', $arguments)) {
            $data['is_active'] = (bool) $arguments['active'];
        }
        if (isset($arguments['frequency'])) {
            if (! isset(RecurringInvoice::FREQUENCIES[$arguments['frequency']])) {
                throw new ToolException('frequency must be weekly, monthly, quarterly or yearly.');
            }
            $data['frequency'] = $arguments['frequency'];
        }
        if (isset($arguments['day_of_month'])) {
            $day = (int) $arguments['day_of_month'];
            if ($day < 1 || $day > 31) {
                throw new ToolException('day_of_month must be 1 to 31.');
            }
            $data['day_of_month'] = $day;
        }
        if (isset($arguments['next_run_on'])) {
            $next = InvoiceToolSupport::date($arguments['next_run_on'], 'next_run_on');
            if ($next < today()->toDateString()) {
                throw new ToolException('next_run_on is in the past -- it would generate back-dated invoices straight away.');
            }
            $data['next_run_on'] = $next;
        }
        if (isset($arguments['due_days'])) {
            $data['due_days'] = (int) $arguments['due_days'] < 0 ? null : (int) $arguments['due_days'];
        }
        foreach (['label', 'intro_text'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $data[$key] = $arguments[$key];
            }
        }
        if (array_key_exists('discount_label', $arguments) || array_key_exists('discount_amount', $arguments)) {
            $removing = ($arguments['discount_label'] ?? null) === '' && (float) ($arguments['discount_amount'] ?? 0) === 0.0;
            $data += $removing
                ? ['discount_label' => null, 'discount_amount' => null]
                : InvoiceToolSupport::discount([
                    'discount_label' => $arguments['discount_label'] ?? $schedule->discount_label,
                    'discount_amount' => $arguments['discount_amount'] ?? $schedule->discount_amount,
                ]);
        }
        $items = array_key_exists('items', $arguments) ? InvoiceToolSupport::items($arguments['items']) : null;

        if ($data === [] && $items === null) {
            throw new ToolException('Nothing to change: pass at least one field.');
        }

        if (($data['frequency'] ?? $schedule->frequency) !== RecurringInvoice::FREQUENCY_WEEKLY
            && ($data['day_of_month'] ?? $schedule->day_of_month) === null) {
            throw new ToolException('A monthly, quarterly or yearly schedule needs day_of_month.');
        }

        $schedule->update($data);
        if ($items !== null) {
            app(InvoiceWriter::class)->replaceScheduleItems($schedule, $items);
        }

        return ['updated' => true, 'before' => $before, 'after' => InvoiceToolSupport::describeSchedule($schedule->refresh()->load('items', 'client'))];
    }
}
