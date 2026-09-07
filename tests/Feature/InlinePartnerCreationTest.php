<?php

namespace Tests\Feature;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Creating a channel partner from inside the Add lead modal — the only way a
 * partner is created at all.
 *
 * Three things are being protected here:
 *
 *   the door       the inline form is gated on "may this user create a lead",
 *                  not on a role. A telecaller cannot create leads and so
 *                  cannot create partners; an admin who grants them `add_leads`
 *                  moves both at once, which is the point of the permission
 *                  being the test.
 *
 *   the seam       the partner and the lead are deliberately NOT one
 *                  transaction. A partner created mid-form has to survive the
 *                  lead beside it failing validation, or a mistyped phone
 *                  number would silently destroy a broker the user had just
 *                  correctly entered.
 *
 *   the duplicates three defences, and the one that matters most is the one
 *                  that still holds when the request did not come from the
 *                  form: a unique index that folds case, punctuation and
 *                  spacing, and that a soft-deleted row does not occupy.
 *
 * @see \App\Http\Controllers\ChannelPartnerController::quickStore()
 * @see \App\Http\Requests\QuickChannelPartnerRequest
 */
class InlinePartnerCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $sales;
    private User $telecaller;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:00'));

        $this->admin      = $this->user('admin', 'Ann');
        $this->sales      = $this->user('salesperson', 'Sam');
        $this->telecaller = $this->user('telecaller', 'Tara');
        $this->project    = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- the door ---------------- */

    public function test_a_telecaller_cannot_reach_the_inline_form(): void
    {
        // no Add lead button, so no modal, so no inline form
        $this->assertFalse($this->leadOptions($this->telecaller)['can']['add']);

        // and the route itself refuses them, which is the answer that counts
        $this->actingAs($this->telecaller)
            ->postJson('/channel-partners/quick', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, ChannelPartner::count());
    }

    public function test_a_salesperson_can_create_one_because_they_can_create_leads(): void
    {
        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload(['name' => 'Ravi Kumar', 'type' => 'broker']))
            ->assertCreated();

        $this->assertSame('Ravi Kumar', ChannelPartner::firstOrFail()->name);
    }

    /**
     * The permission is the test, not the role — so granting a telecaller
     * `add_leads` opens the lead form and the partner form together.
     */
    public function test_a_telecaller_granted_add_leads_can_create_one(): void
    {
        $this->telecaller->update(['permissions' => ['add_leads' => true]]);

        $this->actingAs($this->telecaller)
            ->postJson('/channel-partners/quick', $this->payload())
            ->assertCreated();
    }

    /* ---------------- the three cases, inline ---------------- */

    public function test_an_individual_broker_is_created_inline(): void
    {
        $response = $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'broker', 'name' => 'Kiran Modi',
            ]))
            ->assertCreated();

        $partner = ChannelPartner::firstOrFail();

        $this->assertSame('broker', $partner->type);
        $this->assertNull($partner->parent_id);

        // handed straight back so the select can point at it without a reload
        $response->assertJsonPath('partner.id', $partner->id)
            ->assertJsonPath('partner.label', 'Kiran Modi');
    }

    public function test_a_firm_is_created_inline(): void
    {
        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'firm', 'name' => 'Shreeji Realty',
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.label', 'Shreeji Realty');

        $this->assertSame('firm', ChannelPartner::firstOrFail()->type);
    }

    public function test_a_broker_under_a_firm_is_created_inline(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'broker', 'name' => 'Ravi Kumar', 'parent_id' => $firm->id,
            ]))
            ->assertCreated()
            // the firm is what tells two brokers called Ravi apart
            ->assertJsonPath('partner.label', 'Ravi Kumar — Shreeji Realty');

        $this->assertSame($firm->id, ChannelPartner::where('name', 'Ravi Kumar')->firstOrFail()->parent_id);
    }

    /**
     * Four fields, and `is_active` is not one of them. A partner created to
     * attribute the lead being typed is a partner in use.
     */
    public function test_an_inline_partner_is_always_active(): void
    {
        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload(['is_active' => false]))
            ->assertCreated();

        $this->assertTrue(ChannelPartner::firstOrFail()->is_active);
    }

    public function test_the_four_fields_are_the_only_ones_written(): void
    {
        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload([
                'email'          => 'sneaky@example.test',
                'address'        => 'Somewhere',
                'contact_person' => 'Nobody',
                'alt_phone'      => '9998887776',
            ]))
            ->assertCreated();

        $partner = ChannelPartner::firstOrFail();

        $this->assertNull($partner->email);
        $this->assertNull($partner->address);
        $this->assertNull($partner->contact_person);
        $this->assertNull($partner->alt_phone, 'these are filled in later, from the admin page');
    }

    /* ---------------- the partner survives the lead ---------------- */

    public function test_a_partner_created_inline_survives_a_failed_lead_validation(): void
    {
        $partner = $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload(['type' => 'broker', 'name' => 'Ravi Kumar']))
            ->assertCreated()
            ->json('partner');

        // the lead the user was in the middle of, with a bad mobile number
        $this->actingAs($this->sales)
            ->post('/leads', $this->leadPayload([
                'source'             => 'broker',
                'channel_partner_id' => $partner['id'],
                'mobile_number'      => '123',
            ]))
            ->assertSessionHasErrors('mobile_number');

        $this->assertSame(0, Lead::count(), 'the lead did not save');
        $this->assertDatabaseHas('channel_partners', [
            'id' => $partner['id'], 'deleted_at' => null,
        ]);

        // and the corrected submit lands on the same partner
        $this->actingAs($this->sales)
            ->post('/leads', $this->leadPayload([
                'source'             => 'broker',
                'channel_partner_id' => $partner['id'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($partner['id'], Lead::firstOrFail()->channel_partner_id);
    }

    /* ---------------- defence 3: the unique index ---------------- */

    public function test_the_same_name_in_a_different_case_is_refused(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'firm', 'name' => 'shreeji realty',
            ]))
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, ChannelPartner::count());
    }

    public function test_punctuation_and_spacing_do_not_make_a_new_partner(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        foreach (['Shreeji  Realty', 'Shreeji-Realty', 'SHREEJI REALTY.', ' shreeji   realty '] as $spelling) {
            $this->actingAs($this->sales)
                ->postJson('/channel-partners/quick', $this->payload(['type' => 'firm', 'name' => $spelling]))
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame(1, ChannelPartner::count());
    }

    /**
     * The index is on name AND type. A firm and a broker may share a name —
     * "Shreeji Realty" the firm and "Shreeji Realty" the one-man broker are a
     * real pair, and the admin merges them if they turn out to be one.
     */
    public function test_the_same_name_under_a_different_type_is_allowed(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'broker', 'name' => 'Shreeji Realty',
            ]))
            ->assertCreated();

        $this->assertSame(2, ChannelPartner::count());
    }

    public function test_a_soft_deleted_name_is_free_to_use_again(): void
    {
        $partner = $this->partner('firm', 'Shreeji Realty');

        $partner->delete();

        $this->assertNull(
            $partner->fresh()->name_key,
            'the key is released on delete, which is what takes the row out of the index',
        );

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload(['type' => 'firm', 'name' => 'Shreeji Realty']))
            ->assertCreated();
    }

    public function test_an_inactive_clash_says_so_rather_than_offering_it(): void
    {
        $this->partner('firm', 'Shreeji Realty', ['is_active' => false]);

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/quick', $this->payload(['type' => 'firm', 'name' => 'Shreeji Realty']))
            ->assertJsonValidationErrors('name')
            ->assertJsonFragment(['name' => ['"Shreeji Realty" already exists as a firm but is switched off. Ask an admin to reactivate it rather than adding a second one.']]);
    }

    /**
     * The index is the guarantee, not the rule that reads nicely. Written
     * behind the application's back, it still holds.
     */
    public function test_the_database_itself_refuses_the_duplicate(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ChannelPartner::create([
            'name' => 'SHREEJI  realty', 'type' => 'firm', 'phone' => '9876543210',
        ]);
    }

    /* ---------------- defence 2: the near-match ---------------- */

    public function test_the_near_match_fires_across_case_and_trade_words(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');

        foreach (['shreeji realty', 'Shreeji', 'SHREEJI ESTATE', 'Shreeji Properties', 'shreeji-realty'] as $typed) {
            $this->assertTrue(
                ChannelPartner::nearMatches($typed)->contains('id', $firm->id),
                "\"$typed\" should warn against \"Shreeji Realty\"",
            );
        }
    }

    /** The same question over the wire, which is how the form actually asks it. */
    public function test_the_form_is_warned_about_a_near_match_through_the_endpoint(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/check-name', ['name' => 'shreeji realty'])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $firm->id)
            ->assertJsonPath('matches.0.label', 'Shreeji Realty')
            ->assertJsonPath('matches.0.is_active', true);
    }

    /**
     * The gap the browser cannot close on its own: the form is only ever shipped
     * the ACTIVE partners, so a near-match against a switched-off row raises
     * nothing on screen unless the server says so.
     */
    public function test_the_endpoint_warns_about_a_switched_off_partner_the_form_cannot_see(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty', ['is_active' => false]);

        $this->assertCount(
            0,
            $this->leadOptions($this->sales)['channelPartners'],
            'the picker has nothing to warn from',
        );

        $this->actingAs($this->sales)
            ->postJson('/channel-partners/check-name', ['name' => 'Shreeji'])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $firm->id)
            ->assertJsonPath('matches.0.is_active', false);
    }

    public function test_a_telecaller_cannot_enumerate_the_roster_through_the_name_check(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->telecaller)
            ->postJson('/channel-partners/check-name', ['name' => 'Shreeji'])
            ->assertForbidden();
    }

    /**
     * Type is ignored on purpose: a firm entered once as a broker is one of the
     * commonest ways this list goes wrong, and it is exactly the pair an admin
     * would later want to merge.
     */
    public function test_the_near_match_crosses_types_and_includes_inactive_rows(): void
    {
        $broker = $this->partner('broker', 'Shreeji Realty', ['is_active' => false]);

        $this->assertTrue(ChannelPartner::nearMatches('Shreeji')->contains('id', $broker->id));
    }

    public function test_a_genuinely_different_name_does_not_warn(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        $this->assertCount(0, ChannelPartner::nearMatches('Anand Properties'));
        $this->assertCount(0, ChannelPartner::nearMatches('Ravi Kumar'));
    }

    /**
     * A partner called nothing but trade words keeps its whole name as its
     * similarity key. Reduced to the empty string it would match every other
     * name that also emptied out, and the warning would fire on everything and
     * therefore be ignored on everything.
     */
    public function test_a_name_made_only_of_trade_words_does_not_match_everything(): void
    {
        $this->partner('firm', 'Properties');

        $this->assertCount(0, ChannelPartner::nearMatches('Estate'));
        $this->assertCount(1, ChannelPartner::nearMatches('properties'));
    }

    /* ---------------- defence 1: the typeahead has something to search ---------------- */

    public function test_the_lead_form_ships_the_raw_name_the_warning_compares(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');
        $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        $options = $this->leadOptions($this->sales);

        $ravi = collect($options['channelPartners'])->firstWhere('name', 'Ravi Kumar');

        $this->assertNotNull($ravi, 'the picker needs the bare name, not only the joined label');
        $this->assertSame('Ravi Kumar — Shreeji Realty', $ravi['label']);

        // and what the inline form needs to offer a parent firm
        $this->assertSame(['firm', 'broker'], array_keys($options['partnerTypes']));
        $this->assertSame(['Shreeji Realty'], collect($options['partnerFirms'])->pluck('name')->all());
    }

    public function test_a_switched_off_partner_leaves_the_picker_but_keeps_its_leads(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');
        $lead   = $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);

        $broker->update(['is_active' => false]);

        $this->assertCount(0, $this->leadOptions($this->sales)['channelPartners']);
        $this->assertSame($broker->id, $lead->fresh()->channel_partner_id);
    }

    /* ---------------- helpers ---------------- */

    private function leadOptions(User $as): array
    {
        $response = $this->actingAs($as)->get('/leads?reset=1');

        $response->assertOk();

        return $response->viewData('page')['props']['options'];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name'      => 'Shreeji Realty',
            'type'      => 'firm',
            'parent_id' => '',
            'phone'     => '9876543210',
        ];
    }

    private function leadPayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name'     => 'Meera',
            'last_name'      => 'Sharma',
            'mobile_number'  => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'     => $this->project->id,
            'source'         => 'walk_in',
            'stage'          => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at'   => now()->addDay()->format('Y-m-d\TH:i'),
        ];
    }

    private function partner(string $type, string $name, array $attributes = []): ChannelPartner
    {
        return ChannelPartner::create($attributes + [
            'name'  => $name,
            'type'  => $type,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create($attributes + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'in_discussion',
            'assigned_to'   => $this->sales->id,
            'created_by'    => $this->admin->id,
        ]);
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'first_name'    => $name,
            'last_name'     => 'Test',
            'email'         => strtolower($name) . '@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'          => $role,
            'is_active'     => true,
            'password'      => 'password',
        ]);
    }
}
