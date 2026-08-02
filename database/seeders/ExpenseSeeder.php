<?php

namespace Database\Seeders;

use App\Models\Expense;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ExpenseSeeder extends Seeder
{
    /**
     * Chakra Productions' real commitments.
     *
     * The EMI schedule was reconstructed from the month-by-month matrix, whose
     * first row is February 2026 (row 7 = August 2026, the last month totalling
     * 38,872). Installment counts come from that matrix rather than the sheet's
     * column-total row, because the two disagree for five items and only the
     * matrix reconciles with the monthly sums.
     *
     * Idempotent: re-running updates the same rows instead of duplicating them.
     */
    public function run(): void
    {
        $start = Carbon::create(2026, 2, 1);

        // [name, bank, monthly, first month offset from Feb 2026, installments]
        $emis = [
            ['Mic mini', 'Axis', 1117, 0, 8],
            ['Personal Loan EMI', 'Axis', 3200, 0, 9],
            ['Fridge', 'BOI', 1480, 0, 2],
            ['Gimbal', 'BOI', 2188, 0, 7],
            ['a6400 Cam', 'Axis', 3667, 0, 15],
            ['Cam 2 - A', 'BOI', 2500, 1, 11],
            ['Cam 2 - B', 'CANA', 6250, 1, 7],
            ['VR Headset', 'AXIS', 3200, 0, 14],
            ['X5 + Lens', 'CANA', 6300, 1, 17],
            ['Light Setup + Battery', 'FI', 4550, 2, 7],
            ['Amrit (Gpay 2nd Gimbal)', 'Amrit', 5900, 3, 8],
        ];

        foreach ($emis as [$name, $payee, $amount, $offset, $installments]) {
            Expense::updateOrCreate(
                ['name' => $name],
                [
                    'type' => Expense::TYPE_EMI,
                    'payee' => $payee,
                    'amount' => $amount,
                    'start_month' => $start->copy()->addMonthsNoOverflow($offset)->toDateString(),
                    'installments' => $installments,
                    'is_active' => true,
                ]
            );
        }

        // Monthly running costs -- 63,800/month in total.
        // Names only -- the module is called Salaries, so the suffix is noise.
        // Role, joining date and phone are left for the user to fill in.
        $salaries = [
            ['Sanjai', 8000],
            ['Aron', 5000],
            ['Gokul', 2000],
            ['Keerthivasan', 8000],
            ['Annamalai', 8000],
            ['Nitis', 3000],
            ['Kanishka', 15000],
        ];

        foreach ($salaries as [$name, $amount]) {
            Expense::updateOrCreate(
                ['name' => $name],
                [
                    'type' => Expense::TYPE_SALARY,
                    'amount' => $amount,
                    'is_active' => true,
                    'start_month' => null,
                    'installments' => null,
                ]
            );
        }

        $bills = [
            ['Milk', 2600],
            ['Rent', 7500],
            ['Electricity', 1500],
            ['Wifi Bill', 3200],
        ];

        foreach ($bills as [$name, $amount]) {
            Expense::updateOrCreate(
                ['name' => $name],
                [
                    'type' => Expense::TYPE_BILL,
                    'amount' => $amount,
                    'is_active' => true,
                    'start_month' => null,
                    'installments' => null,
                ]
            );
        }
    }
}
