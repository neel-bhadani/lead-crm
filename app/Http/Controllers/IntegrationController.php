<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntegrationSettingsRequest;
use App\Jobs\ProcessMetaLead;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

/**
 * The Integrations page, admin only.
 *
 * Every route is behind `role:admin` on the group in routes/web.php — the
 * sidebar link is hidden for everyone else as well, but that is presentation;
 * the middleware is the answer. Nothing on this page is a permission check.
 *
 * The one rule this controller exists to keep: a stored token never reaches the
 * browser. card() is the only method that serialises an Integration, and it
 * builds its payload key by key rather than handing over the model — a
 * `$integration->toArray()` anywhere here would put the page access token in
 * the page source of an Inertia response.
 */
class IntegrationController extends Controller
{
    public function index()
    {
        $providers = config('integrations.providers');

        // one query for every configured platform rather than one per card
        $rows = Integration::whereIn('provider', array_keys($providers))->get()->keyBy('provider');

        return Inertia::render('Integrations/Index', [
            'cards'   => collect($providers)
                ->map(fn (array $meta, string $key) => $this->card($key, $meta, $rows->get($key)))
                ->values()
                ->all(),
            'events'  => $this->events(),
            'options' => [
                'results'  => config('integrations.results'),
                'projects' => Project::active()->orderBy('name')->get(['id', 'name']),
                /*
                 | Who a lead can be assigned to. Active only — a lead landing
                 | on a switched-off account is a lead nobody sees — and the
                 | role rides along so the dropdown can say "Priya Shah ·
                 | Telecaller" and an admin can tell two Priyas apart.
                 */
                'users'    => User::active()
                    ->orderBy('first_name')
                    ->get(['id', 'first_name', 'last_name', 'role'])
                    ->map(fn (User $u) => [
                        'id'    => $u->id,
                        'label' => $u->display_name,
                        'role'  => config("crm.role_labels.$u->role", $u->role),
                    ]),
                'roleLabels' => config('crm.role_labels'),
            ],
        ]);
    }

    public function update(IntegrationSettingsRequest $request, string $provider)
    {
        abort_unless(config("integrations.providers.$provider.built"), 404);

        $integration = Integration::forProvider($provider);

        $integration->mergeSettings(
            // the tokens, only if the admin actually typed new ones
            $request->changedSecrets() + [
                'page_id'            => $request->input('page_id'),
                'default_project_id' => (int) $request->input('default_project_id'),
                'assign_to_user_id'  => (int) $request->input('assign_to_user_id'),
            ]
        );

        $integration->is_active = $request->boolean('is_active');
        $integration->save();

        // stable across saves — it is pasted into the Meta dashboard, so
        // regenerating it would break a live webhook at the next re-verify
        $integration->ensureVerifyToken();

        return back()->with('success', 'Facebook settings saved.');
    }

    /**
     * Send a test lead through the real import.
     *
     * The same job the webhook queues, with the same normalising, the same
     * duplicate checks, the same lead creation and the same log line. Only the
     * field data is invented, because Meta has no leadgen_id for a form nobody
     * filled in and cannot reach a machine that is not on the internet.
     *
     * dispatchSync, not dispatch: the admin clicked a button and is waiting for
     * an answer, and on a queue with no worker running a dispatched job would
     * look like nothing happening at all. The webhook keeps the real queue,
     * where answering Meta quickly is what matters.
     */
    public function test(string $provider)
    {
        abort_unless(config("integrations.providers.$provider.built"), 404);

        $integration = Integration::forProvider($provider);

        if (! $integration->isReady()) {
            return back()->with('error', 'Configure and switch on the integration before sending a test lead.');
        }

        // "test_" is what makes these findable and deletable later; the random
        // half is what stops a second test being skipped as a duplicate of the
        // first, which is exactly what the idempotency check is supposed to do
        $leadgenId = 'test_' . Str::lower(Str::random(16));

        try {
            ProcessMetaLead::dispatchSync($provider, $leadgenId, [
                ['name' => 'full_name',    'values' => ['Test Lead']],
                // deliberately with a country code: the importer has to strip it
                // back to ten digits or the unique index will not see a repeat
                ['name' => 'phone_number', 'values' => ['+91' . $this->testPhone()]],
                ['name' => 'email',        'values' => ['test.lead@example.com']],
                // an unrecognised question, so the test exercises that branch too
                ['name' => 'preferred_bhk', 'values' => ['3 BHK']],
            ]);
        } catch (Throwable $e) {
            /*
             | The job rethrows what it failed on, because a queued delivery has
             | to be retried and then land in failed_jobs. Here there is no queue
             | and there is a person waiting, so the same failure is a sentence
             | on screen rather than a 500 page — and it has already been written
             | to the activity log by the job, which is where the admin will look
             | next. The commonest cause is the configured user having been
             | deleted since the settings were saved.
             */
            return back()->with('error', 'The test lead failed: ' . $e->getMessage());
        }

        $event = IntegrationEvent::forProvider($provider)->latest('id')->first();

        return back()->with(
            $event?->result === 'created' ? 'success' : 'warning',
            $event?->message ?? 'Test lead sent.'
        );
    }

    /**
     * A phone number for the test lead that will not collide with a real one.
     *
     * The 99999 prefix is not issued to subscribers in India, so a test lead
     * can never be mistaken for a customer and can never sit on the mobile
     * number a real enquiry needs.
     */
    private function testPhone(): string
    {
        return '99999' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * One card's worth of state.
     *
     * The masking is the whole point of this method existing. `settings` is an
     * encrypted array that decrypts on read, so the model holds the tokens in
     * the clear the moment it is loaded — every field the browser gets is named
     * here explicitly, and the two secret ones are replaced by their last four
     * characters.
     *
     * @param  array<string, mixed>  $meta
     */
    private function card(string $provider, array $meta, ?Integration $integration): array
    {
        $built = (bool) $meta['built'];

        return [
            'provider'    => $provider,
            'name'        => $meta['name'],
            'description' => $meta['description'],
            'built'       => $built,

            // "Connected" is switched on AND able to work, never just a saved row
            'connected'   => $built && $integration?->isReady(),
            'configured'  => $built && $integration?->isConfigured(),
            'is_active'   => $built && (bool) $integration?->is_active,
            'last_received_at' => $integration?->last_received_at?->toIso8601String(),

            'settings' => $built ? [
                // never the tokens themselves — the last four characters, so an
                // admin can tell which token is stored without it being usable
                'page_access_token_hint' => $integration?->maskedSetting('page_access_token'),
                'app_secret_hint'        => $integration?->maskedSetting('app_secret'),
                'page_id'                => $integration?->setting('page_id'),
                'default_project_id'     => $integration?->setting('default_project_id'),
                'assign_to_user_id'      => $integration?->setting('assign_to_user_id'),
            ] : null,

            /*
             | The two values that are meant to leave this application: they get
             | pasted into the Meta app dashboard. The verify token is not a
             | secret in the sense the access token is — it proves the endpoint
             | belongs to whoever set the app up, and it is useless without the
             | app secret — so it is shown in full, because an admin cannot
             | paste a masked one.
             */
            'webhook' => $built ? [
                'url'          => route('webhooks.meta.handle', ['provider' => $provider]),
                'verify_token' => $integration?->exists ? $integration->ensureVerifyToken() : null,
            ] : null,
        ];
    }

    /**
     * The activity log: the newest events across every provider.
     *
     * Not per provider, because the question an admin has is "are leads coming
     * in", not "are Facebook leads coming in" — and with three of the four
     * cards stubbed, splitting it would be four tables, three of them empty.
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return IntegrationEvent::recent(config('integrations.log_limit'))
            ->get()
            ->map(fn (IntegrationEvent $e) => [
                'id'          => $e->id,
                'provider'    => $e->provider,
                'provider_name' => config("integrations.providers.$e->provider.name", $e->provider),
                'result'      => $e->result,
                'external_id' => $e->external_id,
                'lead_id'     => $e->lead_id,
                'message'     => $e->message,
                // ISO with the offset; the page formats it in the browser's
                // locale and the app timezone is Asia/Kolkata
                'created_at'  => $e->created_at->toIso8601String(),
            ])
            ->all();
    }
}
