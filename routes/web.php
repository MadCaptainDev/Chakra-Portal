<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmiController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\My\CalendarController as MyCalendarController;
use App\Http\Controllers\My\DashboardController as MyDashboardController;
use App\Http\Controllers\My\TimesheetController as MyTimesheetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TimesheetAdminController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public landing page. Signed-in users go to whichever home their role has.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route(auth()->user()->homeRoute())
        : view('landing');
})->name('home');

/*
 * Employee area. Employees have logins only so they can fill in their own
 * timesheet -- every query inside is scoped to the signed-in user.
 */
Route::middleware('auth')->prefix('my')->name('my.')->group(function () {
    Route::get('dashboard', [MyDashboardController::class, 'index'])->name('dashboard');
    Route::get('calendar', [MyCalendarController::class, 'index'])->name('calendar');

    Route::get('timesheet', [MyTimesheetController::class, 'index'])->name('timesheet');
    Route::post('timesheet', [MyTimesheetController::class, 'store'])->name('timesheet.store');
    Route::put('timesheet/{entry}', [MyTimesheetController::class, 'update'])->name('timesheet.update');
    Route::delete('timesheet/{entry}', [MyTimesheetController::class, 'destroy'])->name('timesheet.destroy');
});

/*
 * Everything below is admin-only. The guard sits on the group so a new admin
 * route is protected by default rather than by remembering to add it.
 */
Route::middleware(['auth', 'admin', 'recurring.catchup'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('clients', ClientController::class);

    // Combined month overview.
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses/pay-all', [ExpenseController::class, 'payAll'])->name('expenses.pay-all');
    Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
    // Retired: each type now manages itself in its own module.
    Route::redirect('expenses/manage', 'expenses')->name('expenses.manage');

    Route::get('emi', [EmiController::class, 'index'])->name('emi.index');
    Route::post('emi', [EmiController::class, 'store'])->name('emi.store');
    Route::put('emi/{emi}', [EmiController::class, 'update'])->name('emi.update');
    Route::delete('emi/{emi}', [EmiController::class, 'destroy'])->name('emi.destroy');

    Route::get('salaries', [SalaryController::class, 'index'])->name('salaries.index');
    Route::post('salaries', [SalaryController::class, 'store'])->name('salaries.store');
    Route::post('salaries/pay-all', [SalaryController::class, 'payAll'])->name('salaries.pay-all');
    Route::post('salaries/{salary}/pay', [SalaryController::class, 'pay'])->name('salaries.pay');
    Route::get('salaries/{salary}', [SalaryController::class, 'show'])->name('salaries.show');
    Route::put('salaries/{salary}', [SalaryController::class, 'update'])->name('salaries.update');
    Route::delete('salaries/{salary}', [SalaryController::class, 'destroy'])->name('salaries.destroy');

    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::post('bills', [BillController::class, 'store'])->name('bills.store');
    Route::post('bills/{bill}/pay', [BillController::class, 'pay'])->name('bills.pay');
    Route::put('bills/{bill}', [BillController::class, 'update'])->name('bills.update');
    Route::delete('bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');

    Route::resource('invoices', InvoiceController::class);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
    Route::post('invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoices.duplicate');
    Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->name('invoices.approve');
    Route::delete('invoices/{invoice}/discard', [InvoiceController::class, 'discard'])->name('invoices.discard');
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    Route::resource('recurring', RecurringInvoiceController::class)->except('show');
    Route::patch('recurring/{recurring}/toggle', [RecurringInvoiceController::class, 'toggle'])->name('recurring.toggle');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('timesheets', [TimesheetAdminController::class, 'index'])->name('timesheets.index');
    Route::get('timesheets/{employee}', [TimesheetAdminController::class, 'show'])->name('timesheets.show');
    Route::post('timesheets/{employee}/points', [TimesheetAdminController::class, 'award'])->name('timesheets.award');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    Route::resource('users', UserController::class)->except(['show', 'edit', 'update']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
