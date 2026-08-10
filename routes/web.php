<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmiController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceTemplateController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\My\CalendarController as MyCalendarController;
use App\Http\Controllers\My\DashboardController as MyDashboardController;
use App\Http\Controllers\My\TimesheetController as MyTimesheetController;
use App\Http\Controllers\OtherExpenseController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PortfolioCategoryController;
use App\Http\Controllers\PortfolioItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPortfolioController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaxonomyTermController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TimesheetAdminController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public landing page. Signed-in users go to whichever home their role has.
Route::get('/', LandingController::class)->name('home');

// Public portfolio screen. Kept off the /portfolio-items admin path so the two
// can never shadow each other.
Route::get('/portfolio', [PublicPortfolioController::class, 'index'])->name('portfolio');

// The case study for one piece. Named portfolio.detail rather than
// portfolio.show because the portfolio.* names belong to the admin CRUD.
Route::get('/portfolio/{portfolioItem}', [PublicPortfolioController::class, 'show'])->name('portfolio.detail');

// Public enquiry form. Throttled because it is unauthenticated and sends mail.
Route::post('/enquiry', [EnquiryController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('enquiry.store');

/*
 * Shared account area — admins and employees both manage their own profile.
 */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

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

    Route::get('timesheets', [TimesheetAdminController::class, 'index'])->name('timesheets.index');
    Route::get('timesheets/{employee}', [TimesheetAdminController::class, 'show'])->name('timesheets.show');
    Route::post('timesheets/{employee}/points', [TimesheetAdminController::class, 'award'])->name('timesheets.award');

    /*
     * Website content: what the public landing page and /portfolio show.
     * The paths are prefixed so neither can shadow the public /portfolio route.
     */
    Route::get('portfolio-items', [PortfolioItemController::class, 'index'])->name('portfolio.index');
    Route::get('portfolio-items/create', [PortfolioItemController::class, 'create'])->name('portfolio.create');
    Route::post('portfolio-items', [PortfolioItemController::class, 'store'])->name('portfolio.store');
    Route::get('portfolio-items/{portfolio}/edit', [PortfolioItemController::class, 'edit'])->name('portfolio.edit');
    Route::put('portfolio-items/{portfolio}', [PortfolioItemController::class, 'update'])->name('portfolio.update');
    Route::delete('portfolio-items/{portfolio}', [PortfolioItemController::class, 'destroy'])->name('portfolio.destroy');

    Route::get('portfolio-categories', [PortfolioCategoryController::class, 'index'])->name('portfolio-categories.index');
    Route::post('portfolio-categories', [PortfolioCategoryController::class, 'store'])->name('portfolio-categories.store');
    Route::put('portfolio-categories/{portfolioCategory}', [PortfolioCategoryController::class, 'update'])->name('portfolio-categories.update');
    Route::delete('portfolio-categories/{portfolioCategory}', [PortfolioCategoryController::class, 'destroy'])->name('portfolio-categories.destroy');

    // Master lists shared across the app -- platforms, formats, objectives,
    // service types, industries and tags, all one screen switched by ?type=.
    Route::get('master-data', [TaxonomyTermController::class, 'index'])->name('taxonomy.index');
    Route::post('master-data', [TaxonomyTermController::class, 'store'])->name('taxonomy.store');
    Route::put('master-data/{taxonomyTerm}', [TaxonomyTermController::class, 'update'])->name('taxonomy.update');
    Route::delete('master-data/{taxonomyTerm}', [TaxonomyTermController::class, 'destroy'])->name('taxonomy.destroy');

    Route::get('team', [TeamMemberController::class, 'index'])->name('team.index');
    Route::post('team', [TeamMemberController::class, 'store'])->name('team.store');
    Route::put('team/{team}', [TeamMemberController::class, 'update'])->name('team.update');
    Route::delete('team/{team}', [TeamMemberController::class, 'destroy'])->name('team.destroy');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    Route::resource('users', UserController::class)->except(['show']);
    Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password');
});

require __DIR__.'/auth.php';
