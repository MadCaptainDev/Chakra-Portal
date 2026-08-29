<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BriefQuestionController;
use App\Http\Controllers\BrowserSessionController;
use App\Http\Controllers\CallSheetController;
use App\Http\Controllers\Client\BriefController as ClientBriefController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\InvoiceController as ClientInvoiceController;
use App\Http\Controllers\Client\ShootController as ClientShootController;
use App\Http\Controllers\Client\WorkController as ClientWorkController;
use App\Http\Controllers\ClientBriefLinkController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientCredentialController;
use App\Http\Controllers\ClientLoginController;
use App\Http\Controllers\CompetitorAccountController;
use App\Http\Controllers\CompetitorSettingController;
use App\Http\Controllers\ContentAccountController;
use App\Http\Controllers\ContentDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\EditorOutputController;
use App\Http\Controllers\EmiController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InstagramConnectionController;
use App\Http\Controllers\InstagramInsightsController;
use App\Http\Controllers\InstagramSettingController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceTemplateController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\McpTokenController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\My\CalendarController as MyCalendarController;
use App\Http\Controllers\My\DashboardController as MyDashboardController;
use App\Http\Controllers\My\RoutineController as MyRoutineController;
use App\Http\Controllers\My\TeamController as MyTeamController;
use App\Http\Controllers\My\TimesheetController as MyTimesheetController;
use App\Http\Controllers\My\TodoController as MyTodoController;
use App\Http\Controllers\NotionSettingController;
use App\Http\Controllers\NotionShootController;
use App\Http\Controllers\OtherExpenseController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PortfolioCategoryController;
use App\Http\Controllers\PortfolioItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicBriefController;
use App\Http\Controllers\PublicPortfolioController;
use App\Http\Controllers\PushSettingController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\RoutineCalendarController;
use App\Http\Controllers\RoutineCheckingController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\SaasProductController;
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
use App\Http\Controllers\WhatsappCampaignController;
use App\Http\Controllers\WhatsappContactController;
use App\Http\Controllers\WhatsappCrmDashboardController;
use App\Http\Controllers\WhatsappPhonebookController;
use App\Http\Controllers\WhatsappQuickReplyController;
use App\Http\Controllers\WhatsappSettingController;
use App\Http\Controllers\WhatsappTemplateController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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

/*
 * The brand brief on a one-time link, for the many clients who have no login.
 *
 * Unauthenticated by necessity and by design: the token in the path is the
 * only credential, so there is no {client} to tamper with and a wrong token is
 * a 404. Throttled because it is a public write -- generously, because a
 * client saving a long form repeatedly is the expected behaviour, not abuse.
 */
Route::middleware('throttle:30,1')->group(function () {
    Route::get('brief/{token}', [PublicBriefController::class, 'show'])->name('brief.public');

    /*
     * withoutMiddleware(ValidateCsrfToken) on these two route objects
     * directly, not a global except() URI pattern in bootstrap/app.php --
     * the logged-in client portal's own brief routes live under client/brief
     * (see the "client." group further down) so a pattern scoped to this
     * prefix would not actually collide with them, but a route-level
     * exemption needs no such reasoning about paths at all: it is tied to
     * the exact route object, so it is correct by construction regardless of
     * what either URI looks like today or is renamed to later.
     *
     * The exemption itself is deliberate, not an oversight: the long random
     * token in the path IS this route's real credential, and it cannot be
     * embedded in a forged cross-site form by anyone who does not already
     * have it -- at which point they already have full access regardless of
     * CSRF. Session-based CSRF protects ambient cookie authority, and there
     * is no ambient authority here to protect. What it was actually doing
     * was silently breaking a long brief-filling session: the wizard
     * autosaves via fetch() every ~900ms of typing, and once the PHP session
     * (SESSION_LIFETIME=120 minutes) expired mid-fill, both autosave and the
     * final submit started failing with 419 -- and fetch() does not treat an
     * HTTP error status as a failure, so the autosave failures were
     * completely silent (see the JS's own saveNow(), which only checks
     * res.ok). A real client (Thillai Pets Clinic) filled out a full brief
     * that was never saved anywhere, client-side or server-side, because of
     * exactly this.
     */
    Route::withoutMiddleware(ValidateCsrfToken::class)->group(function () {
        Route::post('brief/{token}', [PublicBriefController::class, 'update'])->name('brief.public.update');
        Route::post('brief/{token}/submit', [PublicBriefController::class, 'submit'])->name('brief.public.submit');
    });
});

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

    /*
     * Push notification devices. store()/revoke() are called from
     * resources/js/push.js with the raw FCM token, since that is all the
     * browser has; destroy() is the server-rendered "Stop" button,
     * route-model-bound like mcp-tokens above.
     */
    Route::post('/profile/push-tokens', [PushTokenController::class, 'store'])->name('push-tokens.store');
    Route::post('/profile/push-tokens/revoke', [PushTokenController::class, 'revoke'])->name('push-tokens.revoke');
    Route::delete('/profile/push-tokens/{pushToken}', [PushTokenController::class, 'destroy'])->name('push-tokens.destroy');
});

/*
 * Where Instagram returns a client after they authorise.
 *
 * One fixed path with no {client}, because Meta matches the redirect URI
 * exactly. Which client this belongs to comes from the session, never the URL
 * -- see InstagramConnectionController for why that distinction is the whole
 * security design.
 */
Route::middleware('auth')->get('oauth/instagram/callback', [InstagramConnectionController::class, 'callback'])
    ->name('instagram.callback');

/*
 * Employee / working-admin area. Scoped to the signed-in user. The logs-work
 * middleware admits employees and salary-linked admins; pure admins and
 * clients are refused.
 */
Route::middleware(['auth', 'logs-work'])->prefix('my')->name('my.')->group(function () {
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

    /*
     * Repeating duties assigned to this person (or shared team duties they
     * are permitted on). Complete is ownership-checked in the controller —
     * wrong user gets 404, matching timesheet.
     */
    Route::middleware('routines.catchup')->group(function () {
        Route::get('routines', [MyRoutineController::class, 'index'])->name('routines');
        Route::post('routines/complete', [MyRoutineController::class, 'completeMany'])->name('routines.complete-many');
        Route::post('routines/{occurrence}/complete', [MyRoutineController::class, 'complete'])->name('routines.complete');
    });
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

    /*
     * The brand brief. Static segments and no {client}, by design and for the
     * same reason as everything above it: the controller reads the signed-in
     * user's own client_id, so there is no id in the URL to swap for someone
     * else's.
     *
     * Two write routes against one form. Which one was posted to is what tells
     * ClientBriefRequest whether to enforce the required questions -- a saved
     * draft may be as empty as the client likes, a submission may not.
     *
     * Both are POST rather than the PUT that saving a record would normally
     * take, because the two buttons are one form switched by `formaction`, and
     * a form carrying Laravel's _method=PUT field cannot post to a route that
     * is not also PUT. A hidden field rewritten by JavaScript would restore the
     * verb at the cost of making the submit path depend on script running.
     */
    Route::get('brief', [ClientBriefController::class, 'edit'])->name('brief');
    Route::post('brief', [ClientBriefController::class, 'update'])->name('brief.update');
    Route::post('brief/submit', [ClientBriefController::class, 'submit'])->name('brief.submit');
});

/*
 * Shoots and Equipment. Same shape as Scripts above, and outside the admin
 * group for the same two reasons: the admin middleware would refuse a camera
 * operator before any permission was consulted, and recurring.catchup would
 * have somebody opening a call sheet on set generating the studio's invoices.
 */
Route::middleware(['auth', 'module:shoots,view'])->scopeBindings()->group(function () {
    Route::get('shoots', [ShootController::class, 'index'])->name('shoots.index');

    /*
     * "Sync from Notion" on the Shoots screen. One action: fetch Notion's
     * Shoots database, map clients, import into real Shoot rows. There is
     * no separate Notion Shoots page any more -- see NotionShootImporter.
     */
    Route::post('shoots/sync-notion', [NotionShootController::class, 'sync'])
        ->middleware('module:shoots,create')->name('shoots.sync-notion');

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

    /*
     * Instagram analytics. Reading needs only `view` -- it is a local cache
     * read, not a call to Instagram, so it is no more sensitive than any other
     * client screen. Syncing is `edit`: it spends the account's API quota and
     * writes rows, which is a step above merely looking at a chart.
     */
    Route::get('clients/{client}/instagram/insights', [InstagramInsightsController::class, 'show'])
        ->name('instagram.insights');
    // throttle:10,1 is a request-rate ceiling, not the real throttle -- the
    // one that matters is SocialAccount::canSyncNow(), configurable at Setup
    // → Instagram. This is only here so the route itself cannot be hammered
    // by something faster than a person clicking a button.
    Route::post('clients/{client}/instagram/insights/sync', [InstagramInsightsController::class, 'sync'])
        ->middleware(['module:clients,edit', 'throttle:10,1'])->name('instagram.insights.sync');

    /*
     * Monthly report: the same cached data as Insights, reshaped for one
     * calendar month with an audience section and a downloadable PDF.
     * Studio-only -- there is no client-facing route for this at all, the
     * client's copy is whatever PDF the studio hands them.
     */
    Route::get('clients/{client}/instagram/report', [MonthlyReportController::class, 'show'])
        ->name('instagram.report');
    Route::post('clients/{client}/instagram/report/note', [MonthlyReportController::class, 'updateNote'])
        ->middleware('module:clients,edit')->name('instagram.report.note');
    Route::get('clients/{client}/instagram/report/pdf', [MonthlyReportController::class, 'pdf'])
        ->name('instagram.report.pdf');

    // Stored logins. scopeBindings() means a credential belonging to another
    // client 404s on the binding, before the controller runs.
    Route::middleware('module:clients,credentials')->group(function () {
        Route::post('clients/{client}/credentials', [ClientCredentialController::class, 'store'])->name('clients.credentials.store');
        Route::put('clients/{client}/credentials/{credential}', [ClientCredentialController::class, 'update'])->name('clients.credentials.update');
        Route::delete('clients/{client}/credentials/{credential}', [ClientCredentialController::class, 'destroy'])->name('clients.credentials.destroy');
        Route::post('clients/{client}/credentials/{credential}/reveal', [ClientCredentialController::class, 'reveal'])->name('clients.credentials.reveal');
    });

    /*
     * The brand brief the studio holds on a client.
     *
     * Export needs only view: it is the same answers already rendered on the
     * client's page, in a shape that can be pasted into a script document.
     * Handing out or closing the public link is `edit`, because it decides who
     * outside the studio can write to that record.
     */
    Route::get('clients/{client}/brief/export', [ClientBriefLinkController::class, 'export'])->name('clients.brief.export');

    Route::middleware('module:clients,edit')->group(function () {
        Route::post('clients/{client}/brief/link', [ClientBriefLinkController::class, 'issue'])->name('clients.brief.link');
        Route::delete('clients/{client}/brief/link', [ClientBriefLinkController::class, 'revoke'])->name('clients.brief.link.revoke');
        Route::post('clients/{client}/brief/reopen', [ClientBriefLinkController::class, 'reopen'])->name('clients.brief.reopen');
    });

    /*
     * Connecting a client's Instagram account.
     *
     * Behind `manage`, matching the client-login routes: authorising the
     * studio to read somebody's Instagram is the same order of act as issuing
     * them a password, and a bigger one than correcting a phone number.
     *
     * The callback is registered separately below -- Meta allows one exact
     * redirect URI, so it cannot carry a {client}.
     */
    Route::middleware('module:clients,manage')->group(function () {
        Route::post('clients/{client}/social/instagram/connect', [InstagramConnectionController::class, 'connect'])
            ->name('instagram.connect');
        Route::delete('clients/{client}/social/instagram', [InstagramConnectionController::class, 'destroy'])
            ->name('instagram.disconnect');
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
 * Website content: what the public landing page and /portfolio show.
 * The paths are prefixed so neither can shadow the public /portfolio route.
 */
Route::middleware(['auth', 'module:portfolio,view'])->group(function () {
    Route::get('portfolio-items', [PortfolioItemController::class, 'index'])->name('portfolio.index');
    // Recent Instagram posts for the client picked on the create/edit form --
    // see PortfolioItemController::instagramMedia(). Sits at `view` like the
    // rest of this group; the controller itself asserts create-or-edit, since
    // there is no single ability that names "can see the picker".
    Route::get('portfolio-items/instagram-media', [PortfolioItemController::class, 'instagramMedia'])
        ->name('portfolio.instagram-media');
    Route::get('portfolio-items/create', [PortfolioItemController::class, 'create'])
        ->middleware('module:portfolio,create')->name('portfolio.create');
    Route::post('portfolio-items', [PortfolioItemController::class, 'store'])
        ->middleware('module:portfolio,create')->name('portfolio.store');
    Route::get('portfolio-items/{portfolio}/edit', [PortfolioItemController::class, 'edit'])
        ->middleware('module:portfolio,edit')->name('portfolio.edit');
    Route::put('portfolio-items/{portfolio}', [PortfolioItemController::class, 'update'])
        ->middleware('module:portfolio,edit')->name('portfolio.update');
    Route::post('portfolio-items/{portfolio}/regenerate-creative', [PortfolioItemController::class, 'regenerateCreative'])
        ->middleware('module:portfolio,edit')->name('portfolio.regenerate-creative');
    Route::delete('portfolio-items/{portfolio}', [PortfolioItemController::class, 'destroy'])
        ->middleware('module:portfolio,delete')->name('portfolio.destroy');

    /*
     * Categories sit behind `manage` rather than `edit`. Renaming a category
     * re-labels every piece filed under it on the public site, which is a
     * different act from correcting one case study.
     */
    Route::middleware('module:portfolio,manage')->group(function () {
        Route::get('portfolio-categories', [PortfolioCategoryController::class, 'index'])->name('portfolio-categories.index');
        Route::post('portfolio-categories', [PortfolioCategoryController::class, 'store'])->name('portfolio-categories.store');
        Route::put('portfolio-categories/{portfolioCategory}', [PortfolioCategoryController::class, 'update'])->name('portfolio-categories.update');
        Route::delete('portfolio-categories/{portfolioCategory}', [PortfolioCategoryController::class, 'destroy'])->name('portfolio-categories.destroy');
    });
});

Route::middleware(['auth', 'module:team,view'])->group(function () {
    Route::get('team', [TeamMemberController::class, 'index'])->name('team.index');
    Route::post('team', [TeamMemberController::class, 'store'])
        ->middleware('module:team,create')->name('team.store');
    Route::put('team/{team}', [TeamMemberController::class, 'update'])
        ->middleware('module:team,edit')->name('team.update');
    Route::delete('team/{team}', [TeamMemberController::class, 'destroy'])
        ->middleware('module:team,delete')->name('team.destroy');
});

/*
 * Master lists shared across the app -- platforms, formats, objectives,
 * service types, industries and tags, all one screen switched by ?type=.
 * Everything behind `manage`: these lists feed dropdowns everywhere, so
 * renaming one term reaches screens the person editing it may never open.
 */
Route::middleware(['auth', 'module:taxonomy,view'])->group(function () {
    Route::get('master-data', [TaxonomyTermController::class, 'index'])->name('taxonomy.index');

    Route::middleware('module:taxonomy,manage')->group(function () {
        Route::post('master-data', [TaxonomyTermController::class, 'store'])->name('taxonomy.store');
        Route::put('master-data/{taxonomyTerm}', [TaxonomyTermController::class, 'update'])->name('taxonomy.update');
        Route::delete('master-data/{taxonomyTerm}', [TaxonomyTermController::class, 'destroy'])->name('taxonomy.destroy');
    });
});

/*
 * Repeating studio duties. Definitions and calendar. Subjects toggle existing
 * Client Instagram (social_accounts) and Venture (content_accounts) IDs —
 * there is no separate Instagram handles master list.
 * routines.catchup materialises occurrences on first hit of the day.
 */
Route::middleware(['auth', 'module:routines,view', 'routines.catchup'])->group(function () {
    // "Who still owes what" is the daily question, so it is the front door;
    // the calendar answers the occasional one and sits behind it.
    Route::get('routines/check', [RoutineCheckingController::class, 'index'])->name('routines.checking');
    Route::get('routines', [RoutineController::class, 'index'])->name('routines.index');
    Route::get('routines/calendar', [RoutineCalendarController::class, 'index'])->name('routines.calendar');

    Route::middleware('module:routines,create')->group(function () {
        Route::post('routines', [RoutineController::class, 'store'])->name('routines.store');
    });

    Route::middleware('module:routines,edit')->group(function () {
        Route::put('routines/{routine}', [RoutineController::class, 'update'])->name('routines.update');
    });

    Route::middleware('module:routines,delete')->group(function () {
        Route::delete('routines/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');
    });

    Route::middleware('module:routines,manage')->group(function () {
        Route::post('routines/occurrences/{occurrence}/skip', [RoutineCalendarController::class, 'skip'])
            ->name('routines.occurrences.skip');
        Route::post('routines/check/{occurrence}/complete', [RoutineCheckingController::class, 'complete'])
            ->name('routines.checking.complete');
        Route::post('routines/check/{occurrence}/skip', [RoutineCheckingController::class, 'skip'])
            ->name('routines.checking.skip');
    });
});

/*
 * Tracking a competitor's public Instagram account and generating concepts
 * from the reels that outperform it. Connecting the paid Apify/Gemini/
 * Anthropic keys this depends on is a separate, admin-only Setup screen --
 * see the competitor-settings.* group in the admin block below.
 */
Route::middleware(['auth', 'module:competitors,view'])->group(function () {
    Route::get('competitors', [CompetitorAccountController::class, 'index'])->name('competitors.index');
    Route::post('competitors', [CompetitorAccountController::class, 'store'])
        ->middleware('module:competitors,create')->name('competitors.store');
    Route::get('competitors/{competitor}', [CompetitorAccountController::class, 'show'])->name('competitors.show');
    Route::post('competitors/{competitor}/scrape', [CompetitorAccountController::class, 'scrape'])
        ->middleware('module:competitors,create')->name('competitors.scrape');
    Route::delete('competitors/{competitor}', [CompetitorAccountController::class, 'destroy'])
        ->middleware('module:competitors,delete')->name('competitors.destroy');
    Route::post('competitor-reel-analyses/{analysis}/concepts', [CompetitorAccountController::class, 'generateConcepts'])
        ->middleware('module:competitors,create')->name('competitor-reel-analyses.generate-concepts');
});

// Staff-side inbox for the landing page's enquiry form. No `create`: the only
// thing that writes an enquiry is the public form.
Route::middleware(['auth', 'module:enquiries,view'])->group(function () {
    Route::get('enquiries', [EnquiryController::class, 'index'])->name('enquiries.index');
    Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show'])->name('enquiries.show');
    Route::patch('enquiries/{enquiry}/handled', [EnquiryController::class, 'toggleHandled'])
        ->middleware('module:enquiries,edit')->name('enquiries.handled');
    Route::delete('enquiries/{enquiry}', [EnquiryController::class, 'destroy'])
        ->middleware('module:enquiries,delete')->name('enquiries.destroy');
});

Route::middleware(['auth', 'module:announcements,view'])->group(function () {
    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementController::class, 'store'])
        ->middleware('module:announcements,create')->name('announcements.store');
    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])
        ->middleware('module:announcements,edit')->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
        ->middleware('module:announcements,delete')->name('announcements.destroy');
});

/*
 * Everyone's hours. Your own timesheet is not here -- it is a default every
 * account has, under /my. Awarding points is `manage`, because handing out
 * points is a supervisor's act rather than a reader's.
 */
Route::middleware(['auth', 'module:timesheets,view'])->group(function () {
    Route::get('timesheets', [TimesheetAdminController::class, 'index'])->name('timesheets.index');
    Route::get('timesheets/{employee}', [TimesheetAdminController::class, 'show'])->name('timesheets.show');
    Route::post('timesheets/{employee}/points', [TimesheetAdminController::class, 'award'])
        ->middleware('module:timesheets,manage')->name('timesheets.award');
});

/*
 * Money in. recurring.catchup rides along because opening the invoice list is
 * what generates any recurring invoices that have fallen due -- see the
 * middleware for why it is not a scheduled job.
 */
Route::middleware(['auth', 'module:invoices,view', 'recurring.catchup'])->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('invoices/download-pdfs', [InvoiceController::class, 'downloadPdfs'])->name('invoices.download-pdfs');

    /*
     * Declared before invoices/{invoice}: Laravel matches in declaration
     * order, so a literal path registered after the wildcard is never reached
     * -- /invoices/create would be read as a request to show invoice "create".
     */
    Route::middleware('module:invoices,create')->group(function () {
        Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::post('invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoices.duplicate');
    });

    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');

    Route::middleware('module:invoices,edit')->group(function () {
        Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::patch('invoices/{invoice}', [InvoiceController::class, 'update']);
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
    });

    // Approving is its own ability: whoever raises an invoice should not
    // necessarily be the one who signs it off.
    Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])
        ->middleware('module:invoices,approve')->name('invoices.approve');

    Route::middleware('module:invoices,delete')->group(function () {
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::delete('invoices/{invoice}/discard', [InvoiceController::class, 'discard'])->name('invoices.discard');
    });

    // Standing arrangements rather than one-off bills, so `manage`.
    Route::middleware('module:invoices,manage')->group(function () {
        Route::resource('recurring', RecurringInvoiceController::class)->except('show');
        Route::patch('recurring/{recurring}/toggle', [RecurringInvoiceController::class, 'toggle'])->name('recurring.toggle');
    });
});

/*
 * Money out, minus payroll. Salaries is a separate module because it is the
 * only screen here that tells you what a colleague earns.
 */
Route::middleware(['auth', 'module:expenses,view'])->group(function () {
    // Combined month overview.
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    // Retired: each type now manages itself in its own module.
    Route::redirect('expenses/manage', 'expenses')->name('expenses.manage');

    Route::get('emi', [EmiController::class, 'index'])->name('emi.index');
    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('other', [OtherExpenseController::class, 'index'])->name('other.index');

    // Paying is `edit`: it changes a record's state rather than creating one.
    Route::middleware('module:expenses,edit')->group(function () {
        Route::post('expenses/pay-all', [ExpenseController::class, 'payAll'])->name('expenses.pay-all');
        Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
        Route::post('emi/{emi}/pay', [EmiController::class, 'pay'])->name('emi.pay');
        Route::put('emi/{emi}', [EmiController::class, 'update'])->name('emi.update');
        Route::post('bills/{bill}/pay', [BillController::class, 'pay'])->name('bills.pay');
        Route::put('bills/{bill}', [BillController::class, 'update'])->name('bills.update');
        Route::put('other/{other}', [OtherExpenseController::class, 'update'])->name('other.update');
    });

    Route::middleware('module:expenses,create')->group(function () {
        Route::post('emi', [EmiController::class, 'store'])->name('emi.store');
        Route::post('bills', [BillController::class, 'store'])->name('bills.store');
        Route::post('other', [OtherExpenseController::class, 'store'])->name('other.store');
    });

    Route::middleware('module:expenses,delete')->group(function () {
        Route::delete('emi/{emi}', [EmiController::class, 'destroy'])->name('emi.destroy');
        Route::delete('bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');
        Route::delete('other/{other}', [OtherExpenseController::class, 'destroy'])->name('other.destroy');
    });
});

Route::middleware(['auth', 'module:salaries,view'])->group(function () {
    Route::get('salaries', [SalaryController::class, 'index'])->name('salaries.index');
    Route::get('salaries/{salary}', [SalaryController::class, 'show'])->name('salaries.show');

    Route::post('salaries', [SalaryController::class, 'store'])
        ->middleware('module:salaries,create')->name('salaries.store');

    Route::middleware('module:salaries,edit')->group(function () {
        Route::post('salaries/pay-all', [SalaryController::class, 'payAll'])->name('salaries.pay-all');
        Route::post('salaries/{salary}/pay', [SalaryController::class, 'pay'])->name('salaries.pay');
        Route::put('salaries/{salary}', [SalaryController::class, 'update'])->name('salaries.update');
    });

    Route::delete('salaries/{salary}', [SalaryController::class, 'destroy'])
        ->middleware('module:salaries,delete')->name('salaries.destroy');
});

/*
 * Chakra App Studio: client-built software Chakra maintains -- backups and
 * the AMC licensing that keeps it running. See routes/saas-api.php for the
 * bearer-token endpoints the software itself calls; everything here is the
 * session-authenticated admin side.
 */
Route::middleware(['auth', 'module:saas-products,view'])->group(function () {
    Route::get('saas-products', [SaasProductController::class, 'index'])->name('saas-products.index');
    Route::get('saas-products/{saasProduct}', [SaasProductController::class, 'show'])->name('saas-products.show');
    Route::get('saas-products/{saasProduct}/backups/{backup}/download', [SaasProductController::class, 'downloadBackup'])
        ->name('saas-products.backups.download');

    Route::post('saas-products', [SaasProductController::class, 'store'])
        ->middleware('module:saas-products,create')->name('saas-products.store');

    Route::delete('saas-products/{saasProduct}', [SaasProductController::class, 'destroy'])
        ->middleware('module:saas-products,delete')->name('saas-products.destroy');

    // Suspending, reinstating and setting up billing are `manage`: this is
    // the lever a client's continued access literally depends on, not
    // ordinary record-keeping.
    Route::middleware('module:saas-products,manage')->group(function () {
        Route::post('saas-products/{saasProduct}/suspend', [SaasProductController::class, 'suspend'])->name('saas-products.suspend');
        Route::post('saas-products/{saasProduct}/reinstate', [SaasProductController::class, 'reinstate'])->name('saas-products.reinstate');
        Route::post('saas-products/{saasProduct}/setup-amc', [SaasProductController::class, 'setupAmc'])->name('saas-products.setup-amc');
        Route::post('saas-products/{saasProduct}/reissue-token', [SaasProductController::class, 'reissueToken'])->name('saas-products.reissue-token');

        /*
         * The API reference, live -- its own top-level page, never nested
         * inside a specific product's own screen. One Swagger document
         * covers every SaaS product, so there is nothing product-specific
         * to nest it under.
         */
        Route::get('developer', [DeveloperController::class, 'index'])->name('developer.index');
        Route::get('developer/openapi.json', [DeveloperController::class, 'openapi'])->name('developer.openapi');
    });
});

/*
 * WhatsApp CRM: contacts, quick replies and campaigns. Separate from the
 * admin-only `/whatsapp` credentials screen further down (WhatsappSettingController)
 * -- that screen holds the Meta app secret, this is day-to-day work a
 * producer can be granted without being made an admin of the studio.
 */
Route::middleware(['auth', 'module:whatsapp-crm,view'])->prefix('whatsapp-crm')->name('whatsapp-crm.')->group(function () {
    Route::get('/', [WhatsappCrmDashboardController::class, 'index'])->name('index');

    Route::resource('phonebooks', WhatsappPhonebookController::class)->except('show')
        ->middlewareFor('store', 'module:whatsapp-crm,create')
        ->middlewareFor('update', 'module:whatsapp-crm,edit')
        ->middlewareFor('destroy', 'module:whatsapp-crm,delete');

    Route::resource('contacts', WhatsappContactController::class)
        ->middlewareFor('store', 'module:whatsapp-crm,create')
        ->middlewareFor('update', 'module:whatsapp-crm,edit')
        ->middlewareFor('destroy', 'module:whatsapp-crm,delete');

    // "contacts-import", not "contacts/import" -- a sibling path rather than
    // a child of the resource, so it never has to fight the {contact}
    // wildcard for GET/POST contacts/{contact}.
    Route::get('contacts-import', [WhatsappContactController::class, 'importForm'])->name('contacts.import.form');
    Route::post('contacts-import', [WhatsappContactController::class, 'import'])
        ->middleware('module:whatsapp-crm,create')->name('contacts.import');

    Route::resource('quick-replies', WhatsappQuickReplyController::class)->except('show')
        ->middlewareFor('store', 'module:whatsapp-crm,create')
        ->middlewareFor('update', 'module:whatsapp-crm,edit')
        ->middlewareFor('destroy', 'module:whatsapp-crm,delete');

    // Templates are read-only here (Meta owns them) -- "refresh" only busts
    // the cache and re-reads, it does not write anything, so it stays behind
    // the group's own `view` gate rather than a separate ability.
    Route::get('templates', [WhatsappTemplateController::class, 'index'])->name('templates.index');
    Route::post('templates/refresh', [WhatsappTemplateController::class, 'refresh'])->name('templates.refresh');

    // No edit/update -- a campaign with logs under it is not rewritten, only
    // created, cancelled and (via send-now) re-triggered. destroy stays
    // behind `delete` like every other resource here, even though it only
    // ever removes a campaign that never sent anything (see
    // WhatsappCampaignController::destroy()).
    Route::resource('campaigns', WhatsappCampaignController::class)->except(['edit', 'update'])
        ->middlewareFor('store', 'module:whatsapp-crm,create')
        ->middlewareFor('destroy', 'module:whatsapp-crm,delete');

    Route::get('campaigns/{campaign}/progress', [WhatsappCampaignController::class, 'progress'])->name('campaigns.progress');
    Route::post('campaigns/{campaign}/send-now', [WhatsappCampaignController::class, 'sendNow'])
        ->middleware('module:whatsapp-crm,edit')->name('campaigns.send-now');
    Route::post('campaigns/{campaign}/cancel', [WhatsappCampaignController::class, 'cancel'])
        ->middleware('module:whatsapp-crm,edit')->name('campaigns.cancel');
});

/*
 * Everything below is admin-only. The guard sits on the group so a new admin
 * route is protected by default rather than by remembering to add it.
 *
 * instagram.catchup rides along here for the same reason recurring.catchup
 * does: the dashboard is the one screen every admin hits first each day,
 * and it is also where PortfolioSuggestions::best() (a synced-but-unadded
 * video "worth adding") is shown -- that suggestion is only as fresh as the
 * last sync, so the catch-up needs to run before the dashboard reads it,
 * not after.
 */
Route::middleware(['auth', 'admin', 'recurring.catchup', 'instagram.catchup'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // JSON endpoints behind the invoice form's client modal. Both POST: a
    // JSON body carries no _method field for Laravel to spoof PUT from.
    // These stay admin-only -- they exist for the invoice form, which is.
    Route::post('clients/quick', [ClientController::class, 'quickStore'])->name('clients.quick-store');
    Route::post('clients/{client}/quick', [ClientController::class, 'quickUpdate'])->name('clients.quick-update');

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

    /*
     * The brand brief question list. Admin-only alongside Settings: adding a
     * question changes what every client of the studio is asked, which is not
     * per-client work and is not delegated a piece at a time.
     */
    Route::get('instagram-settings', [InstagramSettingController::class, 'edit'])->name('instagram-settings.edit');
    Route::put('instagram-settings', [InstagramSettingController::class, 'update'])->name('instagram-settings.update');
    Route::post('instagram-settings/rotate-token', [InstagramSettingController::class, 'rotate'])->name('instagram-settings.rotate');

    /*
     * The Notion integration. Admin-only, same reasoning as WhatsApp/
     * Instagram above: this one token reads the studio's entire
     * content-planning and shoot-scheduling pipeline.
     */
    Route::get('notion', [NotionSettingController::class, 'edit'])->name('notion.edit');
    Route::put('notion', [NotionSettingController::class, 'update'])->name('notion.update');
    Route::post('notion/recheck', [NotionSettingController::class, 'recheck'])->name('notion.recheck');

    /*
     * The Firebase push connection. Admin-only, same reasoning again: the
     * service account here can send a push as the studio to every device.
     */
    Route::get('push', [PushSettingController::class, 'edit'])->name('push.edit');
    Route::put('push', [PushSettingController::class, 'update'])->name('push.update');
    Route::post('push/test', [PushSettingController::class, 'test'])->name('push.test');

    /*
     * The competitor-analysis pipeline's three paid API keys (Apify, Gemini,
     * Anthropic). Admin-only, same reasoning again -- every key here has a
     * real per-use cost.
     */
    Route::get('competitor-settings', [CompetitorSettingController::class, 'edit'])->name('competitor-settings.edit');
    Route::put('competitor-settings', [CompetitorSettingController::class, 'update'])->name('competitor-settings.update');
    Route::post('competitor-settings/test', [CompetitorSettingController::class, 'test'])->name('competitor-settings.test');

    /*
     * Which Notion venture belongs to which account, and each account's
     * monthly target. Admin-only alongside the other Setup screens: this
     * decides whose numbers are whose on the Content Dashboard.
     */
    Route::get('content-accounts', [ContentAccountController::class, 'edit'])->name('content-accounts.edit');
    Route::put('content-accounts', [ContentAccountController::class, 'update'])->name('content-accounts.update');
    Route::post('content-accounts', [ContentAccountController::class, 'store'])->name('content-accounts.store');
    Route::delete('content-accounts/{contentAccount}', [ContentAccountController::class, 'destroy'])->name('content-accounts.destroy');
    Route::post('content-accounts/auto-map-shoots', [ContentAccountController::class, 'autoMapShoots'])->name('content-accounts.auto-map-shoots');

    Route::get('brief-questions', [BriefQuestionController::class, 'index'])->name('brief-questions.index');
    Route::post('brief-questions', [BriefQuestionController::class, 'store'])->name('brief-questions.store');
    Route::put('brief-questions/{briefQuestion}', [BriefQuestionController::class, 'update'])->name('brief-questions.update');
    Route::delete('brief-questions/{briefQuestion}', [BriefQuestionController::class, 'destroy'])->name('brief-questions.destroy');
    Route::post('brief-questions/{briefQuestion}/restore', [BriefQuestionController::class, 'restore'])->name('brief-questions.restore');

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

    /*
     * Videos published per client account per month, against target.
     * Read-only over the local Notion cache -- the POST refreshes that
     * cache, it does not write to Notion.
     */
    Route::get('content-dashboard', [ContentDashboardController::class, 'index'])->name('content-dashboard.index');
    Route::post('content-dashboard/refresh', [ContentDashboardController::class, 'refresh'])->name('content-dashboard.refresh');
    Route::get('content-dashboard/{contentAccount}', [ContentDashboardController::class, 'show'])->name('content-dashboard.show');

    Route::resource('users', UserController::class)->except(['show']);
    Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password');
});

require __DIR__.'/auth.php';
