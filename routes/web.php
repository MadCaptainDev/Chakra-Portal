<?php

use App\Http\Controllers\BillController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmiController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\OtherExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\InvoiceTemplateController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public landing page. Signed-in staff skip straight to their dashboard.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('landing');
})->name('home');

// Public enquiry form. Throttled because it is unauthenticated and sends mail.
Route::post('/enquiry', [EnquiryController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('enquiry.store');

Route::middleware(['auth', 'recurring.catchup'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Staff-side inbox for the landing page's enquiry form.
    Route::get('enquiries', [EnquiryController::class, 'index'])->name('enquiries.index');
    Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show'])->name('enquiries.show');
    Route::patch('enquiries/{enquiry}/handled', [EnquiryController::class, 'toggleHandled'])->name('enquiries.handled');
    Route::delete('enquiries/{enquiry}', [EnquiryController::class, 'destroy'])->name('enquiries.destroy');

    // JSON endpoints behind the invoice form's client modal. Both POST: a
    // JSON body carries no _method field for Laravel to spoof PUT from.
    // Declared before the resource so neither path is shadowed.
    Route::post('clients/quick', [ClientController::class, 'quickStore'])->name('clients.quick-store');
    Route::post('clients/{client}/quick', [ClientController::class, 'quickUpdate'])->name('clients.quick-update');

    Route::resource('clients', ClientController::class);

    // Combined month overview.
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses/pay-all', [ExpenseController::class, 'payAll'])->name('expenses.pay-all');
    Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
    // Retired: each type now manages itself in its own module.
    Route::redirect('expenses/manage', 'expenses')->name('expenses.manage');

    Route::get('emi', [EmiController::class, 'index'])->name('emi.index');
    Route::post('emi', [EmiController::class, 'store'])->name('emi.store');
    Route::post('emi/{emi}/pay', [EmiController::class, 'pay'])->name('emi.pay');
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

    Route::get('other', [OtherExpenseController::class, 'index'])->name('other.index');
    Route::post('other', [OtherExpenseController::class, 'store'])->name('other.store');
    Route::put('other/{other}', [OtherExpenseController::class, 'update'])->name('other.update');
    Route::delete('other/{other}', [OtherExpenseController::class, 'destroy'])->name('other.destroy');

    Route::resource('invoices', InvoiceController::class);
    Route::post('invoices/download-pdfs', [InvoiceController::class, 'downloadPdfs'])->name('invoices.download-pdfs');
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

    Route::get('invoice-template', [InvoiceTemplateController::class, 'edit'])->name('invoice-template.edit');
    Route::put('invoice-template', [InvoiceTemplateController::class, 'update'])->name('invoice-template.update');
    Route::post('invoice-template/preview', [InvoiceTemplateController::class, 'preview'])->name('invoice-template.preview');
    Route::post('invoice-template/generate-html', [InvoiceTemplateController::class, 'generateHtml'])->name('invoice-template.generate-html');
    Route::post('invoice-template/reset', [InvoiceTemplateController::class, 'reset'])->name('invoice-template.reset');

    Route::resource('users', UserController::class)->except(['show', 'edit', 'update']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
