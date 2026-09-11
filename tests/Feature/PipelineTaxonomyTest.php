<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStage;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFollowUpService;
use App\Support\CrmTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Stages and sources as rows an admin edits, rather than as two arrays in
 * config/crm.php.
 *
 * Six things are being protected here, and they are not the same thing:
 *
 *   the migration   the tables seed from the config they replaced, so the
 *                   application speaks exactly the same vocabulary the moment
 *                   after `migrate` as the moment before it.
 *
 *   the door        every route is admin-only and typing the URL is not a way
 *                   round it.
 *
 *   the locks       a row the application names in PHP cannot be deleted,
 *                   switched off or re-keyed. A row anything at all points at
 *                   cannot be deleted. Both are refused on the server, for a
 *                   request that never went near the screen.
 *
 *   the data        switching a stage off changes NOTHING: the leads standing
 *                   in it keep it, still render with its name and colour, are
 *                   still editable, and still appear on every historical
 *                   report. This is the promise the whole feature rests on.
 *
 *   the order       `sort_order` is the axis order — dropdowns, every
 *                   zero-filled chart, the Leads chip strip and the funnel.
 *                   Dragging a row moves all of them or none of them.
 *
 *   the invariant   `Lead::open()->doesntHave('pendingTodo')->count()` is zero,
 *                   through every one of the above.
 *
 * @see \App\Http\Controllers\PipelineController
 * @see \App\Support\CrmTaxonomy
 */
class PipelineTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tele;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 11:00'));

        $this->admin   = $this->user('admin', 'Ann');
        $this->tele    = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    /* ================================================================
     | The migration
     ================================================================ */

    public function test_the_tables_seed_from_the_config_they_replaced(): void
    {
        $this->assertSame(array_keys(config('crm.stages')), CrmTaxonomy::stageKeys());
        $this->assertSame(config('crm.stages'), CrmTaxonomy::allStages());
        $this->assertSame(config('crm.stage_colors'), CrmTaxonomy::stageColors());
        $this->assertSame(config('crm.sources'), CrmTaxonomy::allSources());

        // terminal is derived from the column now, not from the config list
        $this->assertSame(config('crm.terminal_stages'), CrmTaxonomy::terminalStages());
        $this->assertEqualsCanonicalizing(
            config('crm.terminal_stages'),
            LeadStage::where('is_terminal', true)->pluck('key')->all(),
        );
    }

    public function test_the_five_named_stages_plus_the_handover_stage_are_system_rows(): void
    {
        $this->assertEqualsCanonicalizing(
            ['fresh', 'not_connected', 'site_visit_done', 'booking_done', 'lost', config('crm.handover_stage')],
            LeadStage::where('is_system', true)->pluck('key')->all(),
        );
    }

    /* ================================================================
     | The door
     ================================================================ */

    public function test_a_non_admin_hitting_the_pipeline_urls_directly_gets_403(): void
    {
        $stage  = LeadStage::where('key', 'fresh')->firstOrFail();
        $source = LeadSource::where('key', 'walk_in')->firstOrFail();

        $this->actingAs($this->tele)->get('/pipeline')->assertForbidden();
        $this->actingAs($this->tele)->post('/pipeline/stages', ['label' => 'X', 'color' => '#334155'])->assertForbidden();
        $this->actingAs($this->tele)->put("/pipeline/stages/{$stage->id}", ['label' => 'X'])->assertForbidden();
        $this->actingAs($this->tele)->delete("/pipeline/stages/{$stage->id}")->assertForbidden();
        $this->actingAs($this->tele)->post('/pipeline/stages/order', ['order' => [$stage->id]])->assertForbidden();
        $this->actingAs($this->tele)->put("/pipeline/sources/{$source->id}", ['label' => 'X'])->assertForbidden();
        $this->actingAs($this->tele)->delete("/pipeline/sources/{$source->id}")->assertForbidden();
    }

    public function test_the_admin_can_open_the_page_with_both_tabs(): void
    {
        $this->actingAs($this->admin)->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pipeline/Index')
                ->where('tab', 'stages')
                ->has('stages', 9)
                ->has('sources', 8));

        $this->actingAs($this->admin)->get('/pipeline?tab=sources')
            ->assertInertia(fn ($page) => $page->where('tab', 'sources'));
    }

    /* ================================================================
     | The locks — a system row
     ================================================================ */

    public function test_a_system_stage_cannot_be_deleted(): void
    {
        foreach (['fresh', 'not_connected', 'site_visit_done', 'booking_done', 'lost', config('crm.handover_stage')] as $key) {
            $stage = LeadStage::where('key', $key)->firstOrFail();

            $this->actingAs($this->admin)
                ->delete("/pipeline/stages/{$stage->id}")
                ->assertSessionHasErrors('stage');

            $this->assertDatabaseHas('lead_stages', ['key' => $key]);
        }
    }

    public function test_a_system_stage_cannot_be_deactivated(): void
    {
        $stage = LeadStage::where('key', 'lost')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", [
                'label' => $stage->label, 'color' => $stage->color, 'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($stage->fresh()->is_active);
    }

    public function test_the_handover_stage_cannot_be_deactivated(): void
    {
        $stage = LeadStage::where('key', config('crm.handover_stage'))->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", [
                'label' => $stage->label, 'color' => $stage->color, 'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($stage->fresh()->is_active);
        $this->assertTrue($stage->fresh()->is_system);
    }

    /**
     * The key is not merely refused, it is not a field. A posted `key` is not
     * in validated() and never reaches the row — see LeadStageRequest.
     */
    public function test_a_stage_key_cannot_be_changed_by_posting_one(): void
    {
        $stage = LeadStage::where('key', 'booking_done')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", [
                'label' => 'Booked', 'color' => $stage->color, 'key' => 'booked',
            ])
            ->assertSessionHasNoErrors();

        $stage->refresh();

        $this->assertSame('booking_done', $stage->key, 'the key must be untouchable');
        // ...while the label and colour are the admin's to change
        $this->assertSame('Booked', $stage->label);
    }

    public function test_a_system_stages_terminal_flag_is_frozen(): void
    {
        $stage = LeadStage::where('key', 'lost')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", [
                'label' => $stage->label, 'color' => $stage->color, 'is_terminal' => false,
            ])
            ->assertSessionHasErrors('is_terminal');

        $this->assertTrue($stage->fresh()->is_terminal);
    }

    public function test_a_system_source_cannot_be_deleted_or_deactivated(): void
    {
        $broker = LeadSource::where('key', 'broker')->firstOrFail();

        $this->actingAs($this->admin)->delete("/pipeline/sources/{$broker->id}")
            ->assertSessionHasErrors('source');

        $this->actingAs($this->admin)
            ->put("/pipeline/sources/{$broker->id}", ['label' => $broker->label, 'is_active' => false])
            ->assertSessionHasErrors('is_active');

        $this->assertDatabaseHas('lead_sources', ['key' => 'broker', 'is_active' => true]);
    }

    /* ================================================================
     | The locks — anything pointing at the row
     ================================================================ */

    public function test_a_stage_holding_leads_cannot_be_hard_deleted(): void
    {
        $stage = $this->addStage('Offer sent');
        $lead  = $this->lead();
        $lead->update(['stage' => $stage->key]);

        $this->actingAs($this->admin)
            ->delete("/pipeline/stages/{$stage->id}")
            ->assertSessionHasErrors('stage');

        $this->assertDatabaseHas('lead_stages', ['key' => $stage->key]);
    }

    /**
     * History is the count that gets forgotten. No lead is standing in the
     * stage any more — the one that passed through it has moved on — but three
     * dashboard cards and the Completed tab are read out of these rows.
     */
    public function test_a_stage_named_only_by_history_cannot_be_hard_deleted(): void
    {
        $stage = $this->addStage('Offer sent');
        $lead  = $this->lead();

        Todo::create([
            'lead_id'       => $lead->id,
            'assigned_to'   => $this->tele->id,
            'created_by'    => $this->admin->id,
            'scheduled_at'  => now()->subDay(),
            'type'          => 'call',
            'status'        => 'completed',
            'outcome_stage' => $stage->key,
            'completed_at'  => now()->subDay(),
        ]);

        $this->assertSame(0, Lead::where('stage', $stage->key)->count());

        $this->actingAs($this->admin)
            ->delete("/pipeline/stages/{$stage->id}")
            ->assertSessionHasErrors('stage');

        $this->assertDatabaseHas('lead_stages', ['key' => $stage->key]);
    }

    public function test_a_stage_an_automation_rule_depends_on_cannot_be_hard_deleted(): void
    {
        $stage = $this->addStage('Offer sent');

        AutomationRule::create([
            'name'       => 'Chase the offer',
            'trigger'    => 'stage_changed',
            'trigger_config' => ['stage' => $stage->key],
            'actions'    => [['type' => 'raise_alert']],
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/pipeline/stages/{$stage->id}")
            ->assertSessionHasErrors('stage');

        $this->assertDatabaseHas('lead_stages', ['key' => $stage->key]);
    }

    public function test_a_source_a_rule_names_in_a_condition_cannot_be_hard_deleted(): void
    {
        $source = $this->addSource('Property portal');

        AutomationRule::create([
            'name'       => 'Portal leads to sales',
            'trigger'    => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => $source->key]],
            'actions'    => [['type' => 'raise_alert']],
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/pipeline/sources/{$source->id}")
            ->assertSessionHasErrors('source');
    }

    /** The one case a hard delete is allowed: a word nothing was ever written in. */
    public function test_a_stage_nothing_points_at_can_be_hard_deleted(): void
    {
        $stage = $this->addStage('Offer sent');

        $this->actingAs($this->admin)
            ->delete("/pipeline/stages/{$stage->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('lead_stages', ['key' => $stage->key]);
        $this->assertArrayNotHasKey($stage->key, CrmTaxonomy::allStages());
    }

    /* ================================================================
     | Adding, and the key
     ================================================================ */

    public function test_a_new_stage_is_slugged_from_its_label_and_lands_at_the_end(): void
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => "Offer sent", 'color' => '#334155'])
            ->assertSessionHasNoErrors();

        $stage = LeadStage::where('label', 'Offer sent')->firstOrFail();

        $this->assertSame('offer_sent', $stage->key);
        $this->assertFalse($stage->is_system);
        $this->assertTrue($stage->is_active);
        $this->assertSame((int) LeadStage::max('sort_order'), $stage->sort_order);

        // and it is the last thing every ordered list offers
        $this->assertSame('offer_sent', array_key_last(CrmTaxonomy::stages()));
    }

    public function test_a_colour_outside_the_palette_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => 'Neon', 'color' => '#FFFF00'])
            ->assertSessionHasErrors('color');
    }

    public function test_two_stages_cannot_share_a_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => 'Connected', 'color' => '#334155'])
            ->assertSessionHasErrors('label');
    }

    /* ================================================================
     | Switching a stage off changes nothing
     ================================================================ */

    public function test_an_inactive_stage_still_renders_on_the_leads_already_in_it(): void
    {
        $stage = $this->addStage('Offer sent');
        $lead  = $this->lead();
        $lead->update(['stage' => $stage->key]);

        $this->deactivate($stage);

        // the label map every badge, chip and history line reads still has it
        $this->actingAs($this->admin)->get('/leads')
            ->assertInertia(fn ($page) => $page
                ->where('options.stages.offer_sent', 'Offer sent')
                ->where('options.stageColors.offer_sent', '#334155')
                // ...and the list of what may be CHOSEN does not
                // Inertia hands a closure the prop as a Collection, so every
                // one of these reads it through collect() rather than as an array
                ->where('options.activeStages', fn ($keys) => ! collect($keys)->contains('offer_sent')));

        // the chip strip still counts the lead, so the total still adds up
        $this->actingAs($this->admin)->get('/leads')
            ->assertInertia(fn ($page) => $page
                ->where('stageCounts.total', Lead::count())
                ->where('stageCounts.bars', fn ($bars) => collect($bars)
                    ->firstWhere('key', 'offer_sent')['value'] === 1));
    }

    public function test_a_lead_in_an_inactive_stage_is_still_editable(): void
    {
        $stage = $this->addStage('Offer sent');
        $lead  = $this->lead();
        $lead->update(['stage' => $stage->key]);

        $this->deactivate($stage);

        // the surname changes; the stage the form posts back is the one the
        // lead is already in, which is no longer offered to anybody else
        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->leadPayload([
                'last_name' => 'Corrected',
                'stage'     => $stage->key,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Corrected', $lead->fresh()->last_name);
        $this->assertSame($stage->key, $lead->fresh()->stage);
    }

    public function test_a_new_lead_cannot_be_filed_at_an_inactive_stage(): void
    {
        $stage = $this->addStage('Offer sent');
        $this->deactivate($stage);

        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload(['stage' => $stage->key]))
            ->assertSessionHasErrors('stage');
    }

    public function test_an_inactive_source_still_appears_in_a_historical_report(): void
    {
        $source = $this->addSource('Property portal');
        $lead   = $this->lead();
        $lead->update(['source' => $source->key]);

        $this->deactivate($source, 'sources');

        $this->actingAs($this->admin)->get('/reports/leads?reset=1&range=30&group=source')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => collect($rows)
                    ->firstWhere('key', 'property_portal')['total'] === 1));
    }

    /**
     * A retired stage with nothing in it drops off the report, which is the
     * other half of the same promise: deactivation hides the row everywhere it
     * is empty and keeps it everywhere it is not.
     */
    public function test_an_inactive_stage_with_nothing_in_it_drops_off_the_report(): void
    {
        $stage = $this->addStage('Offer sent');

        $this->actingAs($this->admin)->get('/reports/leads?reset=1&range=30&group=stage')
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => collect($rows)->contains('key', 'offer_sent')));

        $this->deactivate($stage);

        $this->actingAs($this->admin)->get('/reports/leads?reset=1&range=30&group=stage')
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => ! collect($rows)->contains('key', 'offer_sent')));
    }

    /* ================================================================
     | Order
     ================================================================ */

    public function test_reordering_changes_the_axis_order_everywhere(): void
    {
        $stages = LeadStage::ordered()->pluck('id', 'key');

        // drag Lost to the very top
        $order = collect($stages)->values()->all();
        array_unshift($order, array_pop($order));

        $this->actingAs($this->admin)
            ->post('/pipeline/stages/order', ['order' => $order])
            ->assertSessionHasNoErrors();

        $this->assertSame('lost', array_key_first(CrmTaxonomy::allStages()));

        // the dropdown / label map the whole front end reads
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('options.stages', fn ($map) => collect($map)->keys()->first() === 'lost'));

        // the Leads page chip strip
        $this->actingAs($this->admin)->get('/leads')
            ->assertInertia(fn ($page) => $page
                ->where('stageCounts.bars', fn ($bars) => collect($bars)->first()['key'] === 'lost'));

        // the dashboard's two stage charts, which are zero-filled over the
        // same ordered list
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('charts.stagesAllTime.bars', fn ($bars) => collect($bars)->first()['key'] === 'lost')
                ->where('charts.stagesInPeriod.bars', fn ($bars) => collect($bars)->first()['key'] === 'lost'));

        // the leads report, grouped by stage
        $this->actingAs($this->admin)->get('/reports/leads?reset=1&range=30&group=stage')
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => collect($rows)->first()['key'] === 'lost'));
    }

    public function test_reordering_moves_the_funnel_bands(): void
    {
        // Details shared above Connected — two bands of the funnel, swapped
        $ids = LeadStage::ordered()->pluck('id', 'key')->all();

        $order = array_values($ids);
        $from  = array_search($ids['details_shared'], $order, true);
        $to    = array_search($ids['connected'], $order, true);

        array_splice($order, $to, 0, array_splice($order, $from, 1));

        $this->actingAs($this->admin)->post('/pipeline/stages/order', ['order' => $order]);

        $this->actingAs($this->admin)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('charts.funnel.bands', function ($bands) {
                    $keys = collect($bands)->pluck('key')->all();

                    return array_search('details_shared', $keys, true)
                         < array_search('connected', $keys, true);
                }));
    }

    public function test_a_retired_stage_leaves_the_funnel(): void
    {
        $before = $this->funnelKeys();
        $this->assertContains('details_shared', $before);

        $this->deactivate(LeadStage::where('key', 'details_shared')->firstOrFail());

        $this->assertNotContains('details_shared', $this->funnelKeys());
        $this->assertContains('booking_done', $this->funnelKeys());
    }

    /* ================================================================
     | The invariant, and the terminal flag that guards it
     ================================================================ */

    public function test_the_terminal_flag_is_frozen_while_leads_are_in_the_stage(): void
    {
        $stage = $this->addStage('Written off');

        // free while nothing is in it
        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", [
                'label' => $stage->label, 'color' => $stage->color, 'is_terminal' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($stage->fresh()->is_terminal);

        // a lead arrives, and the answer is frozen: clearing it would make an
        // open lead out of one that carries no pending to-do
        $lead = $this->lead();
        $this->service()->changeStage($lead, $stage->key);

        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", [
                'label' => $stage->label, 'color' => $stage->color, 'is_terminal' => false,
            ])
            ->assertSessionHasErrors('is_terminal');

        $this->assertTrue($stage->fresh()->is_terminal);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_new_terminal_stage_closes_a_lead_like_the_built_in_ones(): void
    {
        $stage = $this->addStage('Written off');
        $stage->update(['is_terminal' => true]);

        $lead = $this->lead();
        $this->assertSame(1, $lead->todos()->where('status', 'pending')->count());

        $this->service()->changeStage($lead, $stage->key);

        $this->assertTrue($lead->fresh()->isTerminal());
        $this->assertSame(0, $lead->todos()->where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /**
     * The trap this whole feature could have walked into: a lead that booked
     * before the stage was retired is still booked. Reading only the active
     * rows for `terminalStages()` would pull it back into the open pipeline
     * with no pending to-do and break the invariant on the next request.
     */
    public function test_a_lead_in_a_retired_terminal_stage_stays_closed(): void
    {
        $stage = $this->addStage('Written off');
        $stage->update(['is_terminal' => true]);

        $lead = $this->lead();
        $this->service()->changeStage($lead, $stage->key);

        $this->deactivate($stage);

        $this->assertTrue($lead->fresh()->isTerminal());
        $this->assertContains($stage->key, CrmTaxonomy::terminalStages());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ================================================================
     | The cache
     ================================================================ */

    public function test_the_cached_vocabulary_is_busted_by_every_write(): void
    {
        $this->assertSame('Connected', CrmTaxonomy::stageLabel('connected'));

        LeadStage::where('key', 'connected')->firstOrFail()->update(['label' => 'Reached']);

        $this->assertSame('Reached', CrmTaxonomy::stageLabel('connected'));

        // ...including a reorder, which writes through the query builder and
        // fires no model events
        $order = array_reverse(LeadStage::ordered()->pluck('id')->all());
        $this->actingAs($this->admin)->post('/pipeline/stages/order', ['order' => $order]);

        $this->assertSame('lost', array_key_first(CrmTaxonomy::allStages()));
    }

    /* ================================================================
     | The config fallback
     ================================================================ */

    /**
     * A half-installed application still renders, on the vocabulary the config
     * file has always held.
     *
     * This is not belt-and-braces. `php artisan migrate` on a fresh database
     * resolves framework code that reads this vocabulary before the tables
     * exist, and an installer that only comes up if the steps are run in one
     * particular order is an installer that goes wrong on somebody's laptop.
     */
    public function test_an_empty_table_falls_back_to_the_config_vocabulary(): void
    {
        $this->lead();

        DB::table('lead_stages')->delete();
        DB::table('lead_sources')->delete();
        CrmTaxonomy::flush();

        $this->assertFalse(CrmTaxonomy::usingDatabase());
        $this->assertSame(config('crm.stages'), CrmTaxonomy::allStages());
        $this->assertSame(config('crm.sources'), CrmTaxonomy::allSources());
        $this->assertSame(config('crm.terminal_stages'), CrmTaxonomy::terminalStages());

        // every page still renders...
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $this->actingAs($this->admin)->get('/leads')->assertOk();
        $this->actingAs($this->admin)->get('/reports/leads?reset=1&range=30&group=stage')->assertOk();

        /*
         | ...and a lead can still be saved. An exists rule against an empty
         | table would refuse every stage there is, turning "renders on the old
         | vocabulary" into "no lead can be created" — so the rule falls back to
         | the same list the dropdown was built from.
         */
        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload())
            ->assertSessionHasNoErrors();
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    private function funnelKeys(): array
    {
        $bands = $this->actingAs($this->admin)->get('/dashboard')
            ->viewData('page')['props']['charts']['funnel']['bands'];

        return array_column($bands, 'key');
    }

    private function addStage(string $label): LeadStage
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => $label, 'color' => '#334155'])
            ->assertSessionHasNoErrors();

        return LeadStage::where('label', $label)->firstOrFail();
    }

    private function addSource(string $label): LeadSource
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/sources', ['label' => $label])
            ->assertSessionHasNoErrors();

        return LeadSource::where('label', $label)->firstOrFail();
    }

    private function deactivate(LeadStage|LeadSource $row, string $kind = 'stages'): void
    {
        $payload = $kind === 'stages'
            ? ['label' => $row->label, 'color' => $row->color, 'is_terminal' => $row->is_terminal, 'is_active' => false]
            : ['label' => $row->label, 'is_active' => false];

        $this->actingAs($this->admin)
            ->put("/pipeline/$kind/{$row->id}", $payload)
            ->assertSessionHasNoErrors();

        $this->assertFalse($row->fresh()->is_active);
    }

    private function service(): LeadFollowUpService
    {
        return app(LeadFollowUpService::class);
    }

    private function lead(): Lead
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload())
            ->assertSessionHasNoErrors();

        return Lead::latest('id')->firstOrFail();
    }

    private function leadPayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name'        => 'Meera',
            'last_name'         => 'Sharma',
            'mobile_number'     => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'        => $this->project->id,
            'source'            => 'walk_in',
            'stage'             => 'fresh',
            'follow_up_type'    => 'call',
            'follow_up_at'      => now()->addDay()->format('Y-m-d\TH:i'),
        ];
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name'    => $first,
            'last_name'     => 'Tester',
            'email'         => strtolower($first) . '@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'          => $role,
            'is_active'     => true,
            'password'      => 'password',
        ]);
    }
}
