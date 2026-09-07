<?php

use App\Http\Controllers\ChannelPartnerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /* ---------------- leads ---------------- */

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::post('/leads/check-duplicate', [LeadController::class, 'checkDuplicate'])
        ->name('leads.check-duplicate');

    /*
     | No role middleware on these three any more — LeadPolicy decides, through
     | LeadRequest::authorize() on the first two and $this->authorize() on the
     | third. `role:admin,salesperson` here would have quietly overruled the
     | per-user toggles: a telecaller granted `add_leads` would have been turned
     | away by the router before the permission was ever consulted.
     |
     | The defaults are unchanged, so this is not a widening — a telecaller with
     | untouched toggles still gets the same 403, it now comes from the policy.
     */
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

    Route::middleware('role:admin')->group(function () {
        Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');
    });

    /* ---------------- to-do ---------------- */

    Route::get('/todos', [TodoController::class, 'index'])->name('todos.index');
    Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
    Route::put('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
    Route::post('/todos/{todo}/complete', [TodoController::class, 'complete'])
        ->name('todos.complete');

    /*
     | The clash warning's server half, and nothing more than a warning.
     |
     | Read-only, called from the four forms that book a follow-up while the
     | user is still choosing a time. It refuses nothing: there is no matching
     | validation rule on any of the save routes above, and a save that goes
     | ahead with a known clash succeeds. Every one of those forms is already
     | reachable by the user who is asking, and the one thing worth protecting
     | — the clashing lead's name — is masked by the controller when they could
     | not have opened that lead anyway.
     */
    Route::post('/follow-ups/check-conflict', [TodoController::class, 'checkConflict'])
        ->name('follow-ups.check-conflict');

    /* ---------------- reports ---------------- */

    /*
     | Two routes, ten sidebar links. Every link under Reports is one of these
     | with a different query string — ?group=source, ?status=completed — which
     | the controller resolves and stores the same way every other filter on
     | every other page is resolved and stored.
     |
     | No role middleware: both actions are scoped by scopeVisibleTo and
     | scopeForUser, so a telecaller opening either one gets a report of their
     | own work rather than a 403. The assigned-to grouping, which is the only
     | part that would be meaningless to them, is dropped by the controller.
     */
    Route::get('/reports/leads', [ReportController::class, 'leads'])->name('reports.leads');
    Route::get('/reports/followups', [ReportController::class, 'followUps'])->name('reports.followups');

    /* ---------------- users (admin only) ---------------- */

    /*
     | Every one of these is behind role:admin, on the group rather than on each
     | route, so a route added here later cannot be forgotten. The middleware
     | also re-checks is_active, which is what stops a deactivated admin's live
     | session from carrying on managing staff.
     |
     | Deliberately not permission-gated: managing staff is not one of the five
     | lead toggles and must not become grantable from inside the app.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    /* ---------------- channel partners ---------------- */

    /*
     | CREATING a partner is part of creating a lead, so it is gated like one.
     |
     | This is the only route in the application that writes a channel partner
     | into existence — there is no POST in the admin group below, and no Add
     | button on the Channel Partners page. A partner is added from inside the
     | Add lead modal, at the moment somebody is logging the lead that came
     | through them, which is the only moment anybody actually knows the
     | broker's name and number.
     |
     | No `role:admin`, and it must not have one: a salesperson logging a broker
     | lead has to be able to name the broker. QuickChannelPartnerRequest
     | authorises on LeadPolicy::create — "may this user create a lead" — which
     | is the permission that decides whether they could have opened the form
     | this request comes from at all. A telecaller does not have `add_leads` by
     | default, so they get no Add lead button, no inline form, and a 403 here
     | if they post to it directly.
     |
     | Outside the admin group and above it, so the group's middleware cannot
     | creep onto it later by accident.
     */
    Route::post('/channel-partners/quick', [ChannelPartnerController::class, 'quickStore'])
        ->name('channel-partners.quick-store');

    /*
     | The near-match warning's server half, behind the same door.
     |
     | The inline form warns instantly from the active partners it was shipped;
     | this is what lets it also warn about a partner somebody switched off,
     | which the browser has no way to know about. Read-only, and it hands back
     | nothing the picker beside it does not already show — except the fact that
     | a switched-off row with that name exists, which is precisely the thing
     | the user needs to be told before adding a second one.
     */
    Route::post('/channel-partners/check-name', [ChannelPartnerController::class, 'checkName'])
        ->name('channel-partners.check-name');

    /*
     | MANAGING the partners that already exist. Admin only, on the group and
     | not on each route, for exactly the reason the users group says: a route
     | added here later cannot be forgotten. The middleware re-checks is_active
     | as well, so a deactivated admin's live session cannot go on editing the
     | roster.
     |
     | Not permission-gated, and deliberately not. The five toggles in
     | config('crm.permissions') are about leads; who a company's channel
     | partners are is not one of them and must not become grantable from
     | inside the app. The sidebar link is hidden for non-admins too, but that
     | is presentation — this is the refusal.
     |
     | There is no POST here. Creation is the route above.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/channel-partners', [ChannelPartnerController::class, 'index'])
            ->name('channel-partners.index');
        Route::put('/channel-partners/{partner}', [ChannelPartnerController::class, 'update'])
            ->name('channel-partners.update');
        /*
         | Merge is the heaviest thing on this table — it reattributes every
         | lead that came through one partner to another one — so it is a POST
         | of its own rather than a flag on the update, and it is admin-only
         | twice over: this group, and MergeChannelPartnerRequest::authorize().
         */
        Route::post('/channel-partners/{partner}/merge', [ChannelPartnerController::class, 'merge'])
            ->name('channel-partners.merge');
        Route::delete('/channel-partners/{partner}', [ChannelPartnerController::class, 'destroy'])
            ->name('channel-partners.destroy');
    });

    /* ---------------- integrations (admin only) ---------------- */

    /*
     | Admin only, on the group so a route added here later cannot be forgotten.
     | This is a real boundary and not a tidy sidebar: the settings behind it
     | hold a page access token and an app secret, and the test-lead button
     | writes a lead.
     |
     | The webhook Meta actually calls is NOT here — it is stateless and public,
     | and lives in routes/webhooks.php.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/integrations', [IntegrationController::class, 'index'])
            ->name('integrations.index');

        /*
         | Both of these take a provider, and both are constrained to the ones
         | that are actually built — the same constraint routes/webhooks.php
         | puts on Meta's endpoint, for the same reason.
         |
         | On the route rather than in the controller because the controller is
         | reached through a FormRequest: a PUT to /integrations/whatsapp would
         | otherwise be validated first and come back as "choose a project" for
         | a platform that has no settings to choose anything for. A stub is a
         | 404 whatever the body says.
         |
         | Instagram joins this list by flipping `built` in
         | config/integrations.php — there is no route to add.
         */
        $built = collect(config('integrations.providers'))
            ->filter(fn (array $meta) => $meta['built'])
            ->keys()
            ->all();

        Route::put('/integrations/{provider}', [IntegrationController::class, 'update'])
            ->whereIn('provider', $built)
            ->name('integrations.update');
        Route::post('/integrations/{provider}/test', [IntegrationController::class, 'test'])
            ->whereIn('provider', $built)
            ->name('integrations.test');
    });

    /*
     | No /profile. The three Breeze routes that were here had no Vue page
     | behind them, and DELETE /profile hard-deleted the signed-in account after
     | nothing but a password check — straight past DeleteUserRequest and
     | UserHandoverService, which exist so that a leaving employee's leads and
     | follow-ups are handed to somebody before the row goes. A user is removed
     | on the Users page or not at all.
     */
});

require __DIR__ . '/auth.php';
