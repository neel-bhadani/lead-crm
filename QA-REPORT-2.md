# QA Report 2 — Lead CRM

**Tested:** 8 September 2026
**Build:** Laravel 13.29.0 / PHP 8.5.9 / Vue 3 / MySQL (`lead_crm`), branch `main` with the automation
and projects work uncommitted.
**Method:** the real HTTP kernel — routes, middleware, form requests, policies and Inertia props —
driven as each of the three roles plus a guest, against the live development database. Every write was
followed by the open-lead invariant check. A `mysqldump` was taken before the first write and restored
at the end.

---

## 1. Summary

**Verdict: ready to demo, with fixes needed before it is used in anger.**

The core of this application is in unusually good shape. Every figure on the dashboard reconciles
exactly with an independent query, in every date range I could construct including a single day, an
empty window, a range ending in the future and a backwards one. The Reports pages agree with the
dashboard number for number. The invariant "every open lead has exactly one pending follow-up" survived
every write I could think to make — 8 sources, 9 stage outcomes, handovers, automation chains, imports,
deletions and double submits — and the database's own integrity audit came back clean on all fourteen
checks. Validation is genuinely server-side: none of the twenty-one hostile lead payloads got through,
and role authorization held on all 39 direct-URL probes. The existing test suite is green (436 passed).

What is wrong is concentrated in the seams. A deactivated user's live session keeps full access to
leads until the session expires, because only the `role:admin` middleware re-checks `is_active`. A lead
can be filed against an inactive or even a soft-deleted project, because `project_id` is validated with
a bare `exists:` rule — the dropdown hides those projects but nothing refuses them. An admin-only route
with no button behind it can cancel an open lead's only follow-up and make it invisible. And a manager
granted `see_all_leads` gets a dashboard where five cards are company-wide and one is personal, because
leads are scoped by permission and follow-ups by role.

**Count by severity: 0 blockers, 4 major, 6 minor, 8 observations.**

---

## 2. Blockers

None. Nothing I found breaks a demo of the happy path or destroys data through the UI.

---

## 3. Major

### [MAJ-1] A deactivated user's live session keeps working
Severity: Major
File: `bootstrap/app.php` (the `auth` middleware alias) / `app/Http/Middleware/EnsureUserHasRole.php:19`

Steps:
1. Sign in as `sales2@crm.test`.
2. In another browser, as admin, deactivate that user on the Users page (handing their work over).
3. Back in the first browser, keep using the app.

Expected: the next request is refused and they are returned to the login page — the account has been
switched off.
Actual: `GET /leads` → 200, `GET /dashboard` → 200, `GET /todos` → 200. `POST /leads` **created a lead**
(id 56 in my run), and `PUT /leads/{id}` edited it. The deactivated user goes on working, and files new
leads onto themselves, for up to `SESSION_LIFETIME` (120 minutes). Only the `role:admin` groups refuse
them, because `EnsureUserHasRole` re-checks `is_active` and the plain `auth` middleware does not.

Cause: confirmed. `LoginRequest::authenticate()` puts `is_active => true` in the credentials, so the
check happens *at sign-in only*. Every route outside a `role:` group is behind bare `auth`, which asks
whether a session exists and nothing else. The deactivation path is careful to hand the work over —
but the person it was taken from is still holding the keys.

Suggested fix (not applied): add a middleware to the `web` group that logs out and redirects any
authenticated user whose `is_active` is false, and append it in `bootstrap/app.php`.

---

### [MAJ-2] Cancelling a follow-up leaves an open lead with none, and it disappears
Severity: Major
File: `app/Http/Controllers/TodoController.php:439-446`

Steps:
1. As admin, `DELETE /todos/{id}` for the single pending follow-up on an open lead.
2. Run `Lead::open()->doesntHave('pendingTodo')->count()`.

Expected: 0 — either the cancel is refused while the lead is open, or a replacement is demanded the way
every other path in the application demands one.
Actual: 1. The lead is at `fresh`, holds no pending to-do, and is therefore on no tab of the Follow-ups
page, in neither dashboard panel, in no follow-up report and in nobody's digest. The only trace is a row
on the Leads list. Nothing in the application ever surfaces it again.

```
DELETE /todos/{id} on open lead -> 302 lead stage=fresh pending=0
INV after cancelling the only pending follow-up   zero=1 multi=0 terminalPending=0
```

This is the only write in the whole application that broke the invariant.

Mitigating, and the reason it is not a blocker: **no Vue file calls this route.** I grepped
`resources/js/` for `todos.destroy` and found nothing — there is no Cancel button anywhere. It is a live,
admin-only, unreferenced route. A demo cannot reach it; a stale tab, a script or a future button can.

Cause: confirmed. `destroy()` sets `status = 'cancelled'` and returns. `LeadFollowUpService::schedule()`
is careful to cancel only when a replacement arrives in the same transaction; this bypasses the service
entirely and is the application's second writer of to-do status.

Suggested fix (not applied): refuse the cancel while `$todo->lead` is open unless a replacement is
supplied, or delete the route since nothing calls it.

---

### [MAJ-3] A lead can be filed against an inactive or soft-deleted project
Severity: Major
File: `app/Http/Requests/LeadRequest.php:78` — `'project_id' => ['required', 'exists:projects,id']`

Steps:
1. As admin, open the Add lead modal. In another tab, switch project "Skyline Residency" off.
2. Submit the first form.

Expected: refused — the project is not being sold any more, which is the entire meaning of switching it
off, and `ProjectController::update()` flashes "It has been removed from the Add lead form" to say so.
Actual: the lead is created. `exists:projects,id` queries the raw table, so it accepts both
`is_active = 0` and `deleted_at IS NOT NULL` rows.

The soft-deleted case is worse and I reproduced it directly:

```
ghost project trashed=YES
POST lead onto SOFT-DELETED project -> CREATED id=91 project rel=null
leads page renders it: project=null
```

The lead's `project` relation resolves to null, so the Leads list shows a blank Project cell, the leads
report files it under a group that does not exist, and `/projects/{id}` for that project is a 404. The
same gap exists on the import path — a test lead sent to an inactive project was created silently.

Cause: confirmed. The dropdown is built from `Project::active()` (`LeadController::options()`), and that
filtering is the only thing standing between the form and an inactive project. A hidden option is not a
rule. `ProjectController::destroy()` guards the *reverse* direction well — it refuses to delete a project
that has leads — but nothing guards a lead arriving at a project that has already gone.

Suggested fix (not applied): replace the rule with
`Rule::exists('projects','id')->whereNull('deleted_at')->where('is_active', true)`, plus an exception for
the project the lead being edited already points at (the same shape `channel_partner_id` already uses).
Apply the same check in `IncomingLeadService::import()`.

---

### [MAJ-4] A `see_all_leads` manager gets a dashboard that is half company-wide and half personal
Severity: Major
File: `app/Models/Todo.php:67` (`scopeForUser` tests `role`) vs `app/Models/Lead.php:129`
(`scopeVisibleTo` tests `can_('see_all_leads')`)

Steps:
1. As admin, grant `see_all_leads` to `sales@crm.test` on the Users page.
2. Sign in as that user and open the dashboard.

Expected / Actual, measured side by side against the admin's own dashboard for the same 30-day range:

| | manager | admin |
|---|---|---|
| Leads list total | 55 | 55 |
| New enquiries / Site visits / Bookings / Lost | 16 / 19 / 4 / 14 | 16 / 19 / 4 / 14 |
| **Calls pending** | **7** | **29** |
| Follow-up tab badges | 6 / 1 / 1 / 47 | 24 / 5 / 8 / 170 |

Five cards on that dashboard describe the whole company. The sixth, sitting in the same row, describes
one person — and the two follow-up panels underneath it do too. A sales manager reading "19 site visits,
4 bookings, 7 calls pending" will act on a pipeline of 7 outstanding calls when the real figure is 29.

The Reports section splits the same way: the manager is offered "By assigned to" on the leads report and
is not offered it on the follow-ups report, because `ReportController::dimensions()` asks
`can_('see_all_leads')` for one and `isAdmin()` for the other.

Cause: confirmed, and it is a deliberate-looking split rather than an oversight — `Todo::scopeForUser`
carries no comment about permissions while `Lead::scopeVisibleTo` is documented at length. The permission
was added to leads and never carried across to follow-ups.

Suggested fix (not applied): make `Todo::scopeForUser()` read
`$user->can_('see_all_leads')` the way `Lead::scopeVisibleTo()` does, and use the same test in
`ReportController::dimensions()` for both reports.

---

## 4. Minor

### [MIN-1] A lead added directly at "Booking done" gets no booking date
Severity: Minor
File: `app/Services/LeadFollowUpService.php:77-123` (`onLeadCreated`) vs `:428-431` (`applyStage`)

Steps: add a lead with stage `booking_done` and a booked unit from the Leads page.
Expected: `booking_date` is stamped, as it is when a lead is *moved* to that stage.
Actual: `bdate=null`. All four booked leads in the demo database carry a null `booking_date` for the
same reason (the seeder writes `booked_unit` and not the date).

Cause: confirmed. `onLeadCreated()` records the stage transition but never calls `applyStage()`, which is
where `booking_date` is defaulted to today. Nothing renders the column today — I grepped
`resources/js/` and found it only as a form field in `CompleteTaskModal.vue` — so this is latent rather
than visible, but the moment anything reports on booking dates the demo rows will all be blank.

Suggested fix (not applied): default `booking_date` in `onLeadCreated()` for a lead created at
`booking_done`, or backfill from `created_at`.

---

### [MIN-2] Demoting a salesperson leaves their leads claiming a role they no longer have
Severity: Minor
File: `app/Http/Controllers/UserController.php:139-170`

Steps: give a salesperson a lead at `site_visit_done`, then change their role to telecaller.
Expected: either refused, or `leads.assigned_role` moves with the person.
Actual: saved. The lead now reads `assigned_to = <a telecaller>` with `assigned_role = 'salesperson'`.
The Users screen warns about this beforehand (`advanced_leads_count` is shipped for exactly that), so an
admin is told — but nothing reconciles the column afterwards, and `assigned_role` is what the handover
logic branches on (`schedule()` hands over only when it reads `telecaller`).

Cause: confirmed — the warning is advisory and there is no follow-up write.

Suggested fix (not applied): update `assigned_role` on the departing user's leads inside the same
transaction as the role change.

---

### [MIN-3] Imported leads can land on a deactivated user
Severity: Minor
File: `app/Services/IncomingLeadService.php:214` — `User::find($integration->setting('assign_to_user_id'))`

Expected: the same `active()` filter the Integrations dropdown uses (`IntegrationController::index()`
ships `User::active()`).
Actual: only existence is checked. A user configured as the assignee and later deactivated goes on
receiving every Facebook lead — and a deactivated user's leads appear on no Follow-ups page, which is
precisely the failure the `user_deactivated_open_leads` built-in alert exists to catch after the fact.
A deleted user is handled properly ("The user this integration assigns leads to no longer exists.").

Suggested fix (not applied): `User::active()->find(...)`, with a message naming the switched-off account.

---

### [MIN-4] "Send by API" on a message that has already been opened says nothing useful
Severity: Minor
File: `app/Http/Controllers/MessageQueueController.php:64-75`

Opening a queued message moves it to `opened`; pressing Send by API afterwards flashes
"That message has already left the queue." That is correct but reads as a failure for the commonest
sequence on the Queue tab — open it, notice WhatsApp did not send, press the other button.

Suggested fix (not applied): hide or disable Send by API once `status !== 'queued'`.

---

### [MIN-5] The follow-ups report will not group by person for a `see_all_leads` manager
Severity: Minor
File: `app/Http/Controllers/ReportController.php` — `dimensions()`

Measured: manager's leads dimensions `["stage","source","project","channel_partner","assigned_to"]`;
follow-up dimensions `["type"]`. Same user, same page furniture, two different rules. This is the
front-end face of MAJ-4 and goes away with the same fix.

---

### [MIN-6] The dashboard costs 25 queries
Severity: Minor
File: `app/Http/Controllers/DashboardController.php`

Not a bug and not slow at this data volume, but it is the heaviest page in the application by some
margin (see §8) and the number will not improve as the database grows.

---

## 5. Observations

**O-1 — the dashboard's conversion and the report's conversion disagree, loudly.** For the same 30-day
range the dashboard headline reads **6.3%** and the Reports page total reads **25%**. Both are correct
and both are documented at length in the source: the dashboard asks "of the leads created in this range,
how many have booked since" (a cohort, capped at 100%) and the report asks "bookings in this range over
leads created in this range" (two populations, can exceed 100%). I am flagging it because a client
looking at two screens fifteen seconds apart will see 6.3% and 25% and ask which one is a bug. The
report page carries a column note; the dashboard card does not.

**O-2 — no queue worker means no Facebook leads.** `QUEUE_CONNECTION=database` and the webhook
correctly answers Meta with a 200 and dispatches `ProcessMetaLead` to the queue. If no worker is
running, every real lead sits in `jobs` forever and the Integrations activity log stays empty — which
looks exactly like "Meta is not sending anything". The Test lead button uses `dispatchSync` and works
regardless, so this cannot be caught from the UI. Worth confirming `queue:work` and `schedule:run` are
supervised before the demo; time-triggered rules and all four built-in alerts depend on the latter, and
the Automation page's `schedulerRunning()` heartbeat is the only warning.

**O-3 — MySQL's session timezone is `SYSTEM`.** `@@session.time_zone = SYSTEM` and `NOW()` currently
returns IST because the host is in IST. Nothing in the application relies on it — every boundary is
computed in PHP under `Asia/Kolkata` and I verified the IST-midnight edge behaves — but a deploy onto a
UTC server would shift every `CURRENT_TIMESTAMP` column default by 5½ hours while the PHP-side windows
stayed put.

**O-4 — the handover does not run when a lead is *created* at "Site visit scheduled".** A lead typed in
at the handover stage stays with the telecaller (`owner=2 role=telecaller`), where a lead *moved* to that
stage is passed to a salesperson. Defensible — a backfill is not a handover — but the two paths do differ
and nothing says so on screen.

**O-5 — ping-pong is stopped by the cooldown, not the chain cap.** I built the exact pair of rules the
LoopGuard docblock describes. The lead bounced twice and the third attempt was suppressed with
`result = cooldown`, an admin alert was raised, and the lead ended at the stage the *user* chose. The
whole exchange took 0.05s. The `max_touches_per_chain` cap was never reached, because the per-rule
cooldown bites first at two rules. That is fine; it just means the cap is untested in practice and only
matters for a chain of three or more distinct rules.

**O-6 — the verify token is rendered in full in the page source.** Deliberate and documented (an admin
cannot paste a masked token into Meta's dashboard). The two real secrets — page access token and app
secret — are masked to their last four characters and I confirmed neither string appears anywhere in
the rendered HTML or in the Inertia props.

**O-7 — the clash warning masks the customer's name, correctly.** Same clash, two viewers:
`"Priya Shah already has a call with QAFin Probe at 3:00 PM, and 1 other."` for someone who can open
that lead, `"Priya Shah already has a call at 3:00 PM, and 1 other."` for someone who cannot. Both saves
then went through — it warns and never blocks, as intended.

**O-8 — hostile filter values are dropped individually, not as a set.** `?stage=nope`, `?project_id=abc`,
`?from=2026-13-45`, a 300-character search — each fails its own rule, is dropped from the session state,
and the rest of the filters survive. The page renders. I could not find a filter value that produced an
error page.

---

## 6. What I verified as working

**Auth.** Email login and mobile login both work. Wrong password and unknown user return the identical
message ("These credentials do not match our records.") — no account enumeration. An inactive user
cannot sign in and gets that same message. Rate limiting engages on the 6th attempt with a countdown.
Empty fields are caught with useful copy. A 5,000-character login is handled. Every authenticated page
redirects a guest to `/login`; every POST does too. Logout invalidates the session.

**Roles.** 39 direct-URL probes across the three roles. Telecaller and salesperson get 403 on every one
of `/users`, `/channel-partners`, `/projects`, `/projects/{id}`, `/integrations`, `/automation`,
`/automation/guide` — and on the write routes behind them (`POST /users`, `PUT /users/{id}`,
`DELETE /users/{id}`, `POST/PUT/DELETE /projects`, partner update/delete/merge, `POST /automation/rules`,
`POST /automation/rules/match`, `POST /automation/templates`, `PUT /automation/whatsapp`,
`PUT /integrations/facebook`, `POST /integrations/facebook/test`). No hidden-button-only authorization
found anywhere.

**Lead ownership.** A salesperson could not `GET`, `PUT` or `DELETE` a telecaller's lead (403/403/403).
A telecaller could view their own lead but not edit or delete it (200/403/403) — correct, since
`edit_leads` and `delete_leads` are off for that role by default. The duplicate-check endpoint names the
owner for someone who can see the lead and says "contact the admin" to someone who cannot.

**Lead creation.** All 8 sources create a lead at the requested stage with the right owner and exactly
one pending follow-up. An admin's lead is filed onto the active telecaller with `assigned_role =
telecaller`, as designed. All 21 hostile payloads were refused server-side: empty everything, 101-character
names, 9- and 11-digit mobiles, letters and a negative number in the mobile field, a non-existent project,
`'; DROP TABLE leads;--'` as a project id, bogus stage, bogus source, malformed email, a past follow-up
date, a follow-up one minute in the past, `lost` with no reason, `lost` with an invented reason,
`booking_done` with no unit, and `broker` with no channel partner. Exactly-at-limit names, a `<script>`
tag, unicode/emoji names and a 2099 follow-up date were accepted, correctly.

**Duplicates.** Same mobile + same project refused. Same mobile + different project allowed. After a soft
delete the number is still held and the message says so ("Restore that lead instead of adding it again")
rather than 500ing on the index.

**Follow-ups.** Logging a call with every one of the 9 outcome stages leaves the invariant intact — the
seven open stages each end with exactly one pending follow-up, `booking_done` and `lost` with zero.
The handover fires at `site_visit_scheduled`: owner moved from the telecaller to a salesperson, role
updated, and the new follow-up landed on the salesperson. Past dates are refused on creation, on
reschedule, on reschedule *of an already-overdue task*, and as the next follow-up when logging a call.
A second pending follow-up cannot be added through `POST /todos`. Completing someone else's follow-up is
403; completing an already-completed one is 422. Tab badges and type chips recompute per tab and the
chips sum to the "All" total on all four tabs for all three roles. The Completed tab filters on
`completed_at`, verified against both columns.

**Dashboard.** Every card reproduced with an independent tinker query, exactly, in eight ranges:
Today, 7, 30, a single custom day, an empty 2020 window, a range ending in the future, a backwards range,
and a 26-year span. The last three are rejected and fall back to the 30-day default rather than erroring.
`stagesAllTime` sums to the lead count; `stagesInPeriod` sums to the New enquiries card; `bySource` sums
to the same. A lead created 40 days ago and booked today is counted by the Bookings card in every range
that contains today and by New enquiries only in ranges that contain its creation — the intake/history
split holds. The sign-in digest appears once per session and is null on the second visit.

**Reports.** Both pages, all five lead dimensions and both follow-up dimensions, all four statuses.
Rows sum to the totals strip in every combination. The leads report and the dashboard agree exactly for
the same 30-day range: total 16, visits 19, booked 4, lost 14. Drill-through parity confirmed —
report Waiting longer / Due today / Upcoming read 24 / 5 / 8 and so does the matching Follow-ups tab.
`?group=assigned_to` typed by a non-admin falls back to the default instead of rendering a one-row report.
`?group=../../etc/passwd`, `?range=999` and `?status=nonsense` all fall back cleanly — no request value
reaches a `selectRaw`.

**Users.** Telecaller and salesperson can be created; `role=admin` is refused. Duplicate email and
duplicate mobile are refused, including against soft-deleted rows. Editing without a password leaves the
hash byte-identical. Short passwords, mismatched confirmations, bad emails, 11-digit mobiles and empty
names all refused. Deactivating someone holding open leads without choosing a handover is refused;
with a handover the leads move and the user is switched off in one transaction. Deleting yourself is
refused. Deactivating yourself is refused. The last active admin is protected.

**Channel partners.** All three shapes create correctly (firm, broker under a firm, individual broker).
A case-and-spacing variant of an existing name is caught by the name key ("already exists as a firm.
Use that one instead"). A firm with a parent, a broker under a broker, and a malformed phone are all
refused with the right message. Merge moves the leads and soft-deletes the source. Deleting a firm that
still holds an active broker is refused. Inline creation is open to a salesperson and refused to a
telecaller (403), which matches `add_leads` — and `check-name` is behind the same door, so a telecaller
cannot enumerate the roster.

**Projects.** Create, duplicate-name refusal, bad-type refusal, deactivate (with the explanatory
warning), and delete-when-empty all behave. **Deleting a project that has leads is refused server-side**
— I hit `DELETE /projects/1` directly with no button involved and the 21 leads were untouched. The
soft-delete-plus-refusal pair means `cascadeOnDelete` on `leads.project_id` cannot fire from the app.

**Integrations.** Settings save; the test lead runs the real import end to end and produced a `fresh`
lead on the configured project, assigned to the configured user, with `created_by = null`, the country
code stripped to ten digits, and a pending follow-up due now. Neither the page access token nor the app
secret appears in the rendered HTML or in the Inertia props — I searched the full page source for both
literal strings. Stub providers 404 on both routes. Webhook: an invalid signature and a missing header
are both 403; the verification handshake echoes the challenge for the right token and 403s for the wrong
one. Import idempotency is airtight — the same `leadgen_id` returns `duplicate`, the same mobile with a
new id returns `repeat_enquiry`, and after a soft delete it says to restore the lead instead.

**Automation.** A new rule is saved switched off (`is_active = false`), every time. The Test button
answers with a count and a sample and writes **zero** log rows and leaves `fire_count` at 0 — it does not
fire. A rule that is off does not run when its trigger occurs. Switched on, it fires once and writes
`rule_fired` then the action's own line. The activity log carries `rule_id`, `lead_id`, action, result,
error and timestamp and **no actor column** — a rule cannot be attributed to its author there. The
cooldown suppresses a second run on the same lead inside the hour and logs `suppressed / cooldown`.
The ping-pong pair was stopped and an admin alert raised. `stage_idle` fired correctly from
`automation:run --rules-only`; `lead_assigned` fired on the automatic handover. Six malformed rules
(no name, bogus trigger, bogus action, bogus stage, no actions, `stage_changed` with no stage) were all
refused.

**Alerts.** Deduplication holds at creation: the same type for the same lead and user twice in a row
writes one row. An alert about a lead the recipient cannot see is dropped silently — verified both by
direct probe and by a rule alerting every salesperson about a telecaller's lead (0 written). An alert
to a deactivated user is dropped. `POST /alerts/{id}/read` on somebody else's alert is 403 and leaves
`read_at` null. `/alerts` returned zero foreign rows for all three roles. A full `automation:run` on an
empty alerts table raised 21 stuck-lead alerts and 1 abandoned-account alert; the immediate second run
raised none.

**WhatsApp.** A rule **queues and never sends**: `status = queued`, `mode = click`, `sent_at = null`.
The click-to-send URL generated for a lead stored as `9876543210` is, exactly:

```
https://wa.me/919876543210?text=Hello%20QAAuto%2C%20about%20Skyline%20Residency.%20%E2%80%94%20Priya%20Shah
```

Country code prefixed, `rawurlencode` so spaces are `%20` and not `+`. The open endpoint returns that
same URL and marks the row `opened`, never `sent`.

**Data integrity.** All clean, on the untouched database: 0 leads whose stage disagrees with their
latest history row (across 51 leads with history), 0 duplicate history rows, 0 todos with a missing lead,
0 todos on a soft-deleted lead, 0 leads or todos with a null `assigned_to`, 0 open leads with zero or
multiple pending follow-ups, 0 terminal leads holding one, 0 alerts pointing at a deleted lead, 0 alerts
whose recipient cannot see the lead, 0 leads whose `assigned_role` disagrees with their owner's role.

**Deleting a lead.** Pending follow-ups cancelled, completed ones kept as history, the lead gone from the
list, and every other page — dashboard, leads, all four follow-up tabs, both reports, alerts, users,
projects, project detail, channel partners, integrations, automation — still 200 for all three roles.

**Double submits.** The same lead posted twice creates one row with one follow-up. A double reschedule
leaves one pending. A double complete is 302 then 422.

**Build.** `npm run build` succeeds in 4.33s with no warnings. `php artisan test` is fully green:
436 passed, 2,659 assertions, 23.71s.

---

## 7. Not tested — and why

- **Browser console errors.** No headless browser is available in this environment — `package.json` has
  no Puppeteer or Playwright and none is installed. I could not open a page, so I cannot tell you whether
  any page logs a red line. **This is the largest gap in this report** and it is exactly the class of bug
  the brief singled out. It needs a manual pass with devtools open on all eleven pages.
- **Responsive layout at 1440 / 1024 / 768 / 375px**, modals, and the sidebar drawer. Same reason.
- **Browser back after a modal**, and whether filters survive an actual browser refresh (I verified the
  server keeps them in the session, which is the mechanism, but not the browser behaviour).
- **Sidebar and tab highlighting** — "exactly one item highlighted" is a rendered-DOM question.
- **Session expiry** in the wall-clock sense. I confirmed the mechanism (`SESSION_LIFETIME=120`,
  `SESSION_DRIVER=database`) and that a guest is redirected, but did not wait two hours.
- **Real Meta credentials.** Per the brief, fake data only. The Graph fetch path was exercised as far as
  the 400 from Meta ("Invalid OAuth access token data"), which is the correct failure and is logged to
  `integration_events` — but the success path beyond it is unverified.
- **The queue worker.** Nothing was running, so the real webhook path (dispatch → worker → import) was
  tested by calling `IncomingLeadService` and `dispatchSync` directly rather than through a worker.
- **`max_touches_per_chain`.** The cooldown fires first with two rules; reaching the chain cap needs
  three or more distinct rules on one lead in one request. See O-5.
- **Concurrency.** The `UniqueConstraintViolationException` handlers in `LeadController::store()`,
  `ChannelPartnerController::quickStore()` and `IncomingLeadService::import()` are the fallback for two
  simultaneous writes. I read them; I could not produce a genuine race single-threaded.

---

## 8. Query counts per page

Admin, warm, measured with `DB::enableQueryLog()` on the current data volume (55 leads, 207 todos).

| Page | Queries |
|---|---|
| `/dashboard` | **25** |
| `/automation` | 19 |
| `/leads` | 16 |
| `/leads?page=2` | 17 |
| `/todos` | 15 |
| `/todos?tab=completed&page=2` | 16 |
| `/projects/1` | 11 |
| `/channel-partners` | 9 |
| `/users` (and `?page=2`) | 7 |
| `/integrations` | 7 |
| `/alerts` | 7 |
| `/reports/leads` | 5 |
| `/projects` | 5 |
| `/reports/followups` | 4 |

No N+1 anywhere — the counts are flat across pages, which is the thing that matters. Eager loading is
doing its job on all three list pages. The dashboard's 25 is the sum of six cards, three charts, two
panels and the digest, each an independent aggregate.

---

## 9. Commands and queries used

```sh
# environment
/mnt/c/php/php.exe artisan --version
/mnt/c/php/php.exe artisan migrate:status
/mnt/c/php/php.exe artisan route:list

# backup, taken before the first write
/mnt/c/xampp/mysql/bin/mysqldump.exe -h 127.0.0.1 -u root lead_crm > .qa-tmp/backup-qa2.sql

# the harness: PHPUnit against the LIVE database, no RefreshDatabase
/mnt/c/php/php.exe vendor/bin/phpunit -c .qa-tmp/phpunit.qa.xml --filter <suite>

# the project's own suite (separate lead_crm_test database)
/mnt/c/php/php.exe artisan test           # 436 passed

# front end
npm run build

# scheduled work
php artisan automation:run --alerts-only
php artisan automation:run --rules-only

# restore, and proof it is byte-identical
/mnt/c/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -u root lead_crm < .qa-tmp/backup-qa2.sql
diff <(normalised backup) <(normalised fresh dump)      # 0 lines
```

The invariant, run after every write operation:

```php
Lead::open()->doesntHave('pendingTodo')->count();                          // must be 0
Lead::open()->withCount(['todos as p' => fn($q) => $q->where('status','pending')])
    ->get()->filter(fn($l) => $l->p > 1)->count();                         // must be 0
Lead::whereIn('stage', config('crm.terminal_stages'))
    ->whereHas('todos', fn($q) => $q->where('status','pending'))->count(); // must be 0
```

Dashboard reconciliation (run for each of the eight ranges):

```php
Lead::whereBetween('created_at', [$from, $to])->count();                        // Total leads
Todo::whereHas('lead')->where('outcome_stage', 'site_visit_done')
    ->whereBetween('completed_at', [$from, $to])->distinct('lead_id')->count('lead_id');
Todo::whereHas('lead')->where('outcome_stage', 'booking_done')  /* …as above… */
Todo::whereHas('lead')->where('outcome_stage', 'lost')          /* …as above… */
Todo::hasLead()->pending()->where('scheduled_at', '<=', today()->endOfDay())->count();
```

Integrity audit (§6, last paragraph) — fourteen checks over `leads`, `todos`, `alerts` and `users`,
including the latest-history-row comparison per lead and a `GROUP BY … HAVING count(*) > 1` for
duplicate history rows.

---

## 10. Test data created, and cleanup

Everything below was created through the application's own HTTP routes (or, where noted, through the
service the route calls) and then removed.

| Kind | Created | Removed |
|---|---|---|
| Leads | ~50, ids 56 upward | all force-deleted |
| Todos | ~60, attached to those leads | all force-deleted |
| Users | 4 created (9 more refused by validation, as intended) | all force-deleted |
| Projects | 3 (`QA Test Project`, `QA Bad Type`, `QA Ghost Project`) | all force-deleted |
| Channel partners | 4 (`QA Firm Alpha`, two brokers, one from the sales account) | all force-deleted |
| Automation rules | 7 (alert, privacy, loop A/B, WhatsApp, idle, assigned) | all deleted |
| Message templates | 1 (`QA Template`) | deleted |
| Automation logs / alerts / message logs | ~20 | all deleted |
| Integration row + events | 1 + 8, fake credentials only | all deleted |

Two incidents worth recording, both repaired:

1. **`Alert::truncate()` inside a transaction implicitly committed.** MySQL treats `TRUNCATE` as DDL, so
   my `DB::rollBack()` did not undo it and the `alerts` table went from 27 rows to 22. I restored the
   table from the backup.
2. **That first restore shifted every `alerts.created_at` by 5½ hours.** I had extracted the table's
   section from the dump without mysqldump's `SET TIME_ZONE='+00:00'` header, so the UTC-written
   `timestamp` values were re-read as IST. I re-restored with the header, then restored the entire
   database from `backup-qa2.sql` to be certain.

**Final state — verified, not assumed.** A fresh `mysqldump` diffed against `backup-qa2.sql`
(normalising only the dump's own completion comment) produces **0 differing lines**. Sessions, cache,
`remember_token` and every `updated_at` are back to their original values, and because the restore
replayed the dump's `CREATE TABLE` statements the AUTO_INCREMENT counters are back at their starting
values as well (`leads` 56, `todos` 208, `users` 5, `alerts` 28).

| Table | Baseline | Now |
|---|---|---|
| users | 4 | 4 |
| projects | 3 | 3 |
| leads | 55 | 55 |
| todos | 207 | 207 |
| channel_partners | 7 | 7 |
| integrations | 0 | 0 |
| integration_events | 0 | 0 |
| automation_rules | 8 | 8 |
| message_templates | 6 | 6 |
| alerts | 27 | 27 |
| automation_logs | 0 | 0 |
| message_logs | 0 | 0 |

Soft-deleted rows: 0 in every table, as before. `Lead::open()->doesntHave('pendingTodo')->count()` is 0.

The scratch directory `.qa-tmp/` holds the backup and the test harness. It was already untracked before
this session. Nothing under `app/`, `config/`, `routes/`, `resources/` or `database/` was modified —
`git status` is identical to the snapshot at the start of the session apart from two new untracked
entries, `QA-REPORT-2.md` and `.qa-tmp/`.
