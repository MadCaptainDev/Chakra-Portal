<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BrowserSessionController;
use App\Http\Controllers\McpTokenController;
use App\Http\Controllers\CallSheetController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\InvoiceController as ClientInvoiceController;
use App\Http\Controllers\Client\ShootController as ClientShootController;
use App\Http\Controllers\Client\WorkController as ClientWorkController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientCredentialController;
use App\Http\Controllers\ClientLoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EditorOutputController;
use App\Http\Controllers\EmiController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceTemplateController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\My\CalendarController as MyCalendarController;
use App\Http\Controllers\My\DashboardController as MyDashboardController;
use App\Http\Controllers\My\TeamController as MyTeamController;
use App\Http\Controllers\My\TimesheetController as MyTimesheetController;
use App\Http\Controllers\My\TodoController as MyTodoController;
use App\Http\Controllers\OtherExpenseController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PortfolioCategoryController;
use App\Http\Controllers\PortfolioItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPortfolioController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\ScriptSectionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShootController;
use App\Http\Controllers\ShootCrewController;
use App\Http\Controllers\ShootKitController;
use App\Http\Controllers\TaxonomyTermController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TimesheetAdminController;
use App\Http\Controllers\TimesheetDayController;
use App\Http\Controllers\TodoReviewController;
use App\Http\Controllers\TodoTrackerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsappSettingController;
use Illuminate\Support\Facades\Route;

// Public landing page. Signed-in users go to whichever home their role has.
Route::get('/', LandingController::class)->name('home');

// Public portfolio screen. Kept off the /portfolio-items admin path so the two
// can never shadow each other.
Route::get('/portfolio', [PublicPortfolioController::class, 'index'])->name('portfolio');

// The case study for one piece. Named portfolio.detail rather than
// portfolio.show because the portfolio.* names belong to the admin CRUD.
Route::get('/portfolio/{portfolioItem}', [PublicPortfolioController::class, 'show'])->name('portfolio.detail');

// Privacy policy. Public and outside every auth group on purpose: Meta, Google
// and the app stores fetch this URL as an anonymous stranger, and a policy
// behind a login is a policy they fail the app for.
Route::view('/privacy', 'privacy')->name('privacy');

// Terms of service. Public for the same reason, and asked for by Meta in the
// same breath as the privacy policy.
Route::view('/terms', 'terms')->name('terms');

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

    /*
     * Where this account is signed in. Both routes act on the signed-in user's
     * own session rows and nobody else's -- there is no id in the URL to point
     * somewhere else, by design.
     */
    Route::delete('/profile/devices', [BrowserSessionController::class, 'destroy'])->name('devices.destroy');
    Route::delete('/profile/devices/others', [BrowserSessionController::class, 'destroyOthers'])->name('devices.destroy-others');

    // Keys for connecting Claude. Everyone may make their own -- a token can
    // never reach further than the person who made it.
    Route::post('/profile/mcp-tokens', [McpTokenController::class, 'store'])->name('mcp-tokens.store');
    Route::delete('/profile/mcp-tokens/{token}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');
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

    /*
     * The other half of the same day: the timesheet says what was done, these
     * say what is going to be. Employees write their own -- there is no route
     * here or anywhere else for making somebody else a to-do.
     *
     * status takes the target in a hidden field, one endpoint for every
     * transition. defer is separate because it moves a date, not a state.
     */
    Route::get('todos', [MyTodoController::class, 'index'])->name('todos');
    Route::post('todos', [MyTodoController::class, 'store'])->name('todos.store');
    Route::put('todos/{todo}', [MyTodoController::class, 'update'])->name('todos.update');
    Route::delete('todos/{todo}', [MyTodoController::class, 'destroy'])->name('todos.destroy');
    Route::post('todos/{todo}/status', [MyTodoController::class, 'status'])->name('todos.status');
    Route::post('todos/{todo}/defer', [MyTodoController::class, 'defer'])->name('todos.defer');
});


/*
 * The team's timesheets, and deciding on a day.
 *
 * Outside the admin group on purpose: a manager is an ordinary employee, and
 * the admin middleware would refuse them before anything else was consulted.
 *
 * Who may decide depends on whose day it is -- that person's own manager, or
 * any admin. That is a per-row question a middleware cannot answer, so
 * TimesheetDayController checks it and aborts. The team screen scopes itself to
 * the signed-in manager's own reports the same way the rest of the my/ area
 * scopes to the signed-in user.
 */
Route::middleware('auth')->group(function () {
    Route::get('my/team', [MyTeamController::class, 'index'])->name('my.team');

    Route::post('timesheets/{employee}/day', [TimesheetDayController::class, 'store'])->name('timesheets.day');

    /*
     * Everybody's to-dos for one day. Here for the same two reasons as the
     * screens above: a manager is an ordinary employee, and recurring.catchup
     * would have somebody generating the studio's invoices by opening a to-do
     * board.
     *
     * One screen rather than the my/team + timesheets/ pair, because unlike
     * timesheets an admin and a manager want the same board and differ only in
     * who appears on it. The controller decides that, and refuses anyone who
     * manages nobody.
     */
    Route::get('todos', [TodoTrackerController::class, 'index'])->name('todos.index');

    /*
     * The one thing the tracker writes. Marking your own work done is a claim;
     * a manager's verdict is what makes it a fact, the same split the timesheet
     * draws between logging a day and deciding one.
     *
     * Whether this person manages that person is a per-row question, so
     * TodoReviewController asks it and aborts rather than a middleware trying.
     */
    Route::post('todos/{todo}/review', [TodoReviewController::class, 'store'])->name('todos.review');
});

/*
 * Scripts. The first module gated by granular permissions rather than by the
 * admin flag, so a writer can reach it without being given the books.
 *
 * Deliberately NOT in the admin group below: the admin middleware would refuse
 * a writer before any permission was consulted. Deliberately without
 * recurring.catchup too -- that generates the studio's invoices on the first
 * request of the day, and opening a script should never bill anybody.
 *
 * scopeBindings() makes {section} resolve only within its {script}, so a
 * section id from another script is a 404 rather than something the controller
 * has to remember to check.
 */
Route::middleware(['auth', 'module:scripts,view'])->scopeBindings()->group(function () {
    Route::get('scripts', [ScriptController::class, 'index'])->name('scripts.index');

    // Before the {script} route, or "create" binds as a script id.
    Route::get('scripts/create', [ScriptController::class, 'create'])
        ->middleware('module:scripts,create')->name('scripts.create');
    Route::post('scripts', [ScriptController::class, 'store'])
        ->middleware('module:scripts,create')->name('scripts.store');

    Route::get('scripts/{script}', [ScriptController::class, 'show'])->name('scripts.show');

    Route::get('scripts/{script}/edit', [ScriptController::class, 'edit'])
        ->middleware('module:scripts,edit')->name('scripts.edit');
    Route::put('scripts/{script}', [ScriptController::class, 'update'])
        ->middleware('module:scripts,edit')->name('scripts.update');
    Route::delete('scripts/{script}', [ScriptController::class, 'destroy'])
        ->middleware('module:scripts,delete')->name('scripts.destroy');

    // The editor's own endpoints. All JSON, all behind the edit ability.
    Route::middleware('module:scripts,edit')->group(function () {
        Route::post('scripts/{script}/sections', [ScriptSectionController::class, 'store'])->name('scripts.sections.store');
        Route::post('scripts/{script}/sections/reorder', [ScriptSectionController::class, 'reorder'])->name('scripts.sections.reorder');
        Route::patch('scripts/{script}/sections/{section}', [ScriptSectionController::class, 'update'])->name('scripts.sections.update');
        Route::delete('scripts/{script}/sections/{section}', [ScriptSectionController::class, 'destroy'])->name('scripts.sections.destroy');
    });
});

/*
 * The client area.
 *
 * Outside the admin group for the obvious reason, and outside
 * recurring.catchup for a much less obvious one: that middleware generates the
 * studio's due recurring invoices as a side effect of somebody loading a page.
 * The invoice routes carry it, and a client downloading their own PDF through
 * that group would silently issue the studio's monthly invoices to everybody
 * else. Nothing in here may ever join that group.
 *
 * Ownership is not enforced by these routes. There is no {client} in any path;
 * every controller reads the signed-in user's own client_id, so there is no id
 * to tamper with. See App\Http\Controllers\Client\Concerns\ResolvesClient.
 */
Route::middleware(['auth', 'client'])->prefix('client')->name('client.')->group(function () {
    Route::get('/', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('invoices', [ClientInvoiceController::class, 'index'])->name('invoices');
    Route::get('invoices/{invoice}/pdf', [ClientInvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('work', [ClientWorkController::class, 'index'])->name('work');
    Route::get('shoots', [ClientShootController::class, 'index'])->name('shoots');
});

/*
 * Shoots and Equipment. Same shape as Scripts above, and outside the admin
 * group for the same two reasons: the admin middleware would refuse a camera
 * operator before any permission was consulted, and recurring.catchup would
 * have somebody opening a call sheet on set generating the studio's invoices.
 */
Route::middleware(['auth', 'module:shoots,view'])->scopeBindings()->group(function () {
    Route::get('shoots', [ShootController::class, 'index'])->name('shoots.index');

    // Before {shoot}, or "create" binds as a shoot id.
    Route::get('shoots/create', [ShootController::class, 'create'])
        ->middleware('module:shoots,create')->name('shoots.create');
    Route::post('shoots', [ShootController::class, 'store'])
        ->middleware('module:shoots,create')->name('shoots.store');

    Route::get('shoots/{shoot}', [ShootController::class, 'show'])->name('shoots.show');
    Route::get('shoots/{shoot}/call-sheet', [CallSheetController::class, 'show'])->name('shoots.call-sheet');

    Route::get('shoots/{shoot}/edit', [ShootController::class, 'edit'])
        ->middleware('module:shoots,edit')->name('shoots.edit');
    Route::put('shoots/{shoot}', [ShootController::class, 'update'])
        ->middleware('module:shoots,edit')->name('shoots.update');
    Route::delete('shoots/{shoot}', [ShootController::class, 'destroy'])
        ->middleware('module:shoots,delete')->name('shoots.destroy');

    // Planning who and what goes: producer work.
    Route::middleware('module:shoots,edit')->group(function () {
        Route::post('shoots/{shoot}/crew', [ShootCrewController::class, 'store'])->name('shoots.crew.store');
        Route::delete('shoots/{shoot}/crew/{crew}', [ShootCrewController::class, 'destroy'])->name('shoots.crew.destroy');
        Route::post('shoots/{shoot}/kit', [ShootKitController::class, 'store'])->name('shoots.kit.store');
        Route::delete('shoots/{shoot}/kit/{kit}', [ShootKitController::class, 'destroy'])->name('shoots.kit.destroy');
    });

    /*
     * Ticking the list needs only view. The crew tick their own kit -- that was
     * the whole ask -- while what goes on the list stays the producer's.
     */
    Route::post('shoots/{shoot}/kit/{kit}/check-out', [ShootKitController::class, 'checkOut'])->name('shoots.kit.check-out');
    Route::post('shoots/{shoot}/kit/{kit}/undo', [ShootKitController::class, 'undoCheckOut'])->name('shoots.kit.undo');
    Route::post('shoots/{shoot}/kit/{kit}/check-in', [ShootKitController::class, 'checkIn'])->name('shoots.kit.check-in');
    Route::post('shoots/{shoot}/kit-bulk', [ShootKitController::class, 'bulk'])->name('shoots.kit.bulk');
});

/*
 * Clients, and the logins the studio holds on their behalf.
 *
 * Moved out of the admin group so an account manager can keep client records
 * without being handed the books. Outside recurring.catchup for the reason
 * every non-admin group is: opening a client record must not issue the
 * studio's monthly invoices.
 *
 * The credentials routes carry their own ability. Somebody who tidies client
 * details has no reason to read their Instagram password, and the day one is
 * misused the list of people who could have seen it should be short.
 */
Route::middleware(['auth', 'module:clients,view'])->scopeBindings()->group(function () {
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');

    // Before {client}, or "create" binds as a client id.
    Route::get('clients/create', [ClientController::class, 'create'])
        ->middleware('module:clients,create')->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])
        ->middleware('module:clients,create')->name('clients.store');

    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');

    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])
        ->middleware('module:clients,edit')->name('clients.edit');
    Route::match(['put', 'patch'], 'clients/{client}', [ClientController::class, 'update'])
        ->middleware('module:clients,edit')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])
        ->middleware('module:clients,delete')->name('clients.destroy');

    // Stored logins. scopeBindings() means a credential belonging to another
    // client 404s on the binding, before the controller runs.
    Route::middleware('module:clients,credentials')->group(function () {
        Route::post('clients/{client}/credentials', [ClientCredentialController::class, 'store'])->name('clients.credentials.store');
        Route::put('clients/{client}/credentials/{credential}', [ClientCredentialController::class, 'update'])->name('clients.credentials.update');
        Route::delete('clients/{client}/credentials/{credential}', [ClientCredentialController::class, 'destroy'])->name('clients.credentials.destroy');
        Route::post('clients/{client}/credentials/{credential}/reveal', [ClientCredentialController::class, 'reveal'])->name('clients.credentials.reveal');
    });

    /*
     * Issuing and revoking a client's login. Behind `manage` rather than
     * `edit`, because it creates a user account that can sign in -- a bigger
     * act than correcting a phone number.
     */
    Route::middleware('module:clients,manage')->group(function () {
        Route::post('clients/{client}/login', [ClientLoginController::class, 'store'])->name('clients.login.store');
        Route::put('clients/{client}/login', [ClientLoginController::class, 'updatePassword'])->name('clients.login.password');
        Route::delete('clients/{client}/login', [ClientLoginController::class, 'destroy'])->name('clients.login.destroy');
    });
});

Route::middleware(['auth', 'module:equipment,view'])->group(function () {
    Route::get('equipment', [EquipmentController::class, 'index'])->name('equipment.index');
    Route::post('equipment', [EquipmentController::class, 'store'])
        ->middleware('module:equipment,create')->name('equipment.store');
    Route::put('equipment/{equipment}', [EquipmentController::class, 'update'])
        ->middleware('module:equipment,edit')->name('equipment.update');
    Route::delete('equipment/{equipment}', [EquipmentController::class, 'destroy'])
        ->middleware('module:equipment,delete')->name('equipment.destroy');
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
    // These stay admin-only -- they exist for the invoice form, which is.
    Route::post('clients/quick', [ClientController::class, 'quickStore'])->name('clients.quick-store');
    Route::post('clients/{client}/quick', [ClientController::class, 'quickUpdate'])->name('clients.quick-update');

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

    /*
     * The WhatsApp connection. Admin-only rather than a permission module: the
     * app secret on this screen can send messages as the studio, and connecting
     * the studio's own Meta app is not work that gets delegated in pieces.
     *
     * The endpoint Meta actually calls is in routes/webhooks.php, deliberately
     * outside every auth group -- this is only where a human configures it.
     */
    Route::get('whatsapp', [WhatsappSettingController::class, 'edit'])->name('whatsapp.edit');
    Route::put('whatsapp', [WhatsappSettingController::class, 'update'])->name('whatsapp.update');
    Route::post('whatsapp/rotate-token', [WhatsappSettingController::class, 'rotate'])->name('whatsapp.rotate');

    Route::get('invoice-template', [InvoiceTemplateController::class, 'edit'])->name('invoice-template.edit');
    Route::put('invoice-template', [InvoiceTemplateController::class, 'update'])->name('invoice-template.update');
    Route::post('invoice-template/preview', [InvoiceTemplateController::class, 'preview'])->name('invoice-template.preview');
    Route::post('invoice-template/generate-html', [InvoiceTemplateController::class, 'generateHtml'])->name('invoice-template.generate-html');
    Route::post('invoice-template/reset', [InvoiceTemplateController::class, 'reset'])->name('invoice-template.reset');

    /*
     * Output against hours, and the timesheet rows that make a rate a lie.
     * Admin only and deliberately not module-gated: this is the screen that
     * ranks people against each other, and who sees it is the owner's call to
     * make on purpose rather than by ticking a permission box.
     */
    Route::get('editors', [EditorOutputController::class, 'index'])->name('editors.index');

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
