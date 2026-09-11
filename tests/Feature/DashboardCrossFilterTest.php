<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The three things the BI rebuild added: cross-filtering, the funnel and the
 * sparklines.
 *
 * The first assertion in this file is the one the rest of the dashboard rests
 * on — that with nothing selected, every figure is exactly what it was. Every
 * cross-filter is an additional `where` on a query that was going to run
 * anyway, and an empty cross-filter adds none of them, so the unfiltered page
 * is not "checked to still be right": it is running the same SQL.
 *
 * @see \App\Http\Controllers\DashboardController
 */
class DashboardCrossFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = $this->user('admin');
        $this->project = Project::create(['name' => 'Alpha']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- nothing selected changes nothing ---------------- */

    /**
     * Naming the three cross-filter keys with empty values is the same page as
     * not naming them at all. That is what the front end sends when a filter
     * is switched off — withoutEmpty() drops it on the way out — so the two
     * requests have to be the same request.
     */
    public function test_an_empty_cross_filter_is_the_unfiltered_dashboard(): void
    {
        $this->fixture();

        foreach (['range=today', 'range=7', 'range=30', 'from=2026-08-01&to=2026-08-31'] as $range) {
            $bare = $this->props('cards', $range);
            $empty = $this->props('cards', "$range&stage=&source=&reached=");

            $this->assertSame($bare, $empty, $range);

            $this->assertSame(
                $this->props('charts', $range),
                $this->props('charts', "$range&stage=&source=&reached="),
                $range,
            );
        }
    }

    /** A value that is not a configured key never reaches a query builder. */
    public function test_a_cross_filter_value_outside_config_is_dropped(): void
    {
        $this->fixture();

        $filters = $this->props('filters', 'range=30&stage=not_a_stage&source=carrier_pigeon');

        $this->assertSame(['stage' => '', 'source' => '', 'reached' => ''], $filters);

        // and the page it produces is the unfiltered one
        $this->assertSame(
            $this->props('cards', 'range=30'),
            $this->props('cards', 'range=30&stage=not_a_stage&source=carrier_pigeon'),
        );
    }

    /* ---------------- a filter reaches every tile ---------------- */

    /**
     * One stage, and every figure on the page moves with it — the six cards,
     * both stage charts, the source ring, the funnel AND the two follow-up
     * panels at the foot, which are the ones a filter is most easily forgotten
     * on because no date range has ever touched them.
     */
    public function test_a_stage_filter_narrows_every_card_chart_and_both_panels(): void
    {
        $this->fixture();

        $all = $this->page('range=30');
        $one = $this->page('range=30&stage=connected');

        // Bina is the only lead standing at connected, and she came in today
        $this->assertSame(3, $all['cards']['total']);
        $this->assertSame(1, $one['cards']['total']);

        // the census loses its window but not the filter
        $this->assertSame(4, $all['charts']['stagesAllTime']['total']);
        $this->assertSame(1, $one['charts']['stagesAllTime']['total']);
        $this->assertSame(['connected' => 1], $this->nonZero($one['charts']['stagesAllTime']['bars']));

        // the ring keeps only her source
        $this->assertSame(['facebook'], array_column($one['charts']['bySource'], 'key'));

        // the event cards are her history and nobody else's
        $this->assertSame(2, $all['cards']['visits']);
        $this->assertSame(0, $one['cards']['visits']);

        // and both panels, which no date range has ever moved
        $this->assertSame(3, $all['followUps']['today']['total']);
        $this->assertSame(1, $one['followUps']['today']['total']);
        $this->assertSame(2, $all['followUps']['overdue']['total']);
        $this->assertSame(0, $one['followUps']['overdue']['total']);
        $this->assertSame(5, $all['cards']['pending']);
        $this->assertSame(1, $one['cards']['pending']);
    }

    /** The same, on the doughnut's dimension. */
    public function test_a_source_filter_narrows_every_tile(): void
    {
        $this->fixture();

        $page = $this->page('range=30&source=referral');

        // Chetan alone came from a referral, and he came in 40 days ago
        $this->assertSame(0, $page['cards']['total'], 'created outside the 30-day window');
        $this->assertSame(1, $page['charts']['stagesAllTime']['total']);
        $this->assertSame(1, $page['cards']['booked']);
        $this->assertSame(1, $page['cards']['visits']);
        $this->assertSame(1, $page['followUps']['overdue']['total']);
        $this->assertSame(0, $page['followUps']['today']['total']);
    }

    /**
     * `reached` is a different question from `stage`: not where a lead stands
     * now, but somewhere it has been. Chetan stands at booking_done and has
     * been through site_visit_done, so a site-visit filter holds him and a
     * stage filter for the same value does not.
     */
    public function test_a_reached_filter_selects_leads_that_have_been_through_a_stage(): void
    {
        $this->fixture();

        $reached = $this->page('range=30&reached=site_visit_done');
        $standing = $this->page('range=30&stage=site_visit_done');

        $this->assertSame(2, $reached['charts']['stagesAllTime']['total'], 'Asha and Chetan both visited');
        $this->assertSame(1, $standing['charts']['stagesAllTime']['total'], 'only Asha still stands there');

        $this->assertSame(
            ['site_visit_done' => 1, 'booking_done' => 1],
            $this->nonZero($reached['charts']['stagesAllTime']['bars']),
        );
    }

    /** Two filters at once are one narrower population, not two pages. */
    public function test_two_cross_filters_apply_together(): void
    {
        $this->fixture();

        $page = $this->page('range=30&stage=connected&source=walk_in');

        // Bina is connected but came from facebook, so the pair holds nobody
        $this->assertSame(0, $page['charts']['stagesAllTime']['total']);
        $this->assertSame(0, $page['cards']['pending']);
        $this->assertSame([], $page['charts']['bySource']);
    }

    /**
     * Clearing restores the original numbers exactly — not approximately, and
     * not merely the ones somebody thought to check. The whole `cards` and
     * `charts` payloads are compared.
     */
    public function test_clearing_the_cross_filter_restores_the_page_exactly(): void
    {
        $this->fixture();

        $before = $this->page('range=30');

        // filter, then filter again on something else, then clear
        $this->props('cards', 'range=30&stage=connected');
        $this->props('cards', 'range=30&source=facebook&reached=booking_done');

        $after = $this->page('range=30');

        $this->assertSame($before['cards'], $after['cards']);
        $this->assertSame($before['charts'], $after['charts']);
        $this->assertSame($before['followUps'], $after['followUps']);
    }

    /**
     * The filter is stored, so a bare visit keeps it — and it arrives from the
     * query string, which is what makes a refresh on a shared link land on the
     * same filtered dashboard rather than on the unfiltered one.
     */
    public function test_a_cross_filter_arrives_on_the_url_and_survives_a_bare_visit(): void
    {
        $this->fixture();

        $this->assertSame(
            ['stage' => 'connected', 'source' => '', 'reached' => ''],
            $this->props('filters', 'range=30&stage=connected'),
        );

        // a plain visit, naming nothing at all
        $bare = $this->actingAs($this->admin)->withHeaders([
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ])->get('/dashboard')->json('props.filters');

        $this->assertSame(['stage' => 'connected', 'source' => '', 'reached' => ''], $bare);
    }

    /* ---------------- the funnel ---------------- */

    /** Six stages, in progression order, and always all six. */
    public function test_the_funnel_draws_the_six_progression_stages_in_order(): void
    {
        $this->fixture();

        $bands = $this->props('charts', 'from=2026-07-01&to=2026-07-05')['funnel']['bands'];

        $this->assertSame(
            ['fresh', 'connected', 'details_shared', 'site_visit_done', 'in_discussion', 'booking_done'],
            array_column($bands, 'key'),
        );

        // an empty range still draws all six, at zero, with no drop to report
        $this->assertSame([0, 0, 0, 0, 0, 0], array_column($bands, 'value'));
        $this->assertSame([null, null, null, null, null, null], array_column($bands, 'drop'));

        // and a stage's colour is the one config gives it, everywhere
        $this->assertSame(config('crm.stage_colors.booking_done'), $bands[5]['color']);
    }

    /**
     * THE reconciliation. The Site visit done and Booking done bands are not
     * figures that agree with the matching cards — they are the same figure,
     * because the funnel is stageEvents() and so are the cards.
     *
     * Asserted unfiltered and under each kind of cross-filter, because a
     * filter that reached one of the two and not the other is exactly how they
     * would come apart.
     */
    public function test_the_funnel_bands_are_the_matching_kpi_cards(): void
    {
        $this->fixture();

        $queries = [
            'range=today', 'range=7', 'range=30', 'from=2026-08-01&to=2026-09-02',
            'range=30&stage=connected',
            'range=30&source=referral',
            'range=30&reached=site_visit_done',
            'range=30&stage=booking_done&source=referral',
        ];

        foreach ($queries as $query) {
            $page  = $this->page($query);
            $bands = collect($page['charts']['funnel']['bands'])->pluck('value', 'key');

            $this->assertSame($page['cards']['visits'], $bands['site_visit_done'], $query);
            $this->assertSame($page['cards']['booked'], $bands['booking_done'], $query);
        }
    }

    /**
     * The drop from the band above, and the two cases that are not a number.
     *
     * `fresh` has no band above it. It is also always zero — arriving is not
     * something anybody logs, so nothing ever writes it to `outcome_stage` —
     * which makes the band below it a drop from an empty band. Both are an em
     * dash on the page; both are null here. Never 0%.
     */
    public function test_the_funnel_guards_a_drop_off_against_an_empty_band(): void
    {
        $this->fixture();

        $bands = collect($this->props('charts', 'range=30')['funnel']['bands'])->keyBy('key');

        $this->assertSame(0, $bands['fresh']['value'], 'fresh is never a logged outcome');
        $this->assertNull($bands['fresh']['drop'], 'nothing above the top band');
        $this->assertNull($bands['connected']['drop'], 'the band above it is empty');

        /*
         | Where there is something to divide by, there is a number — and 0.0
         | is one of them. "Nobody was lost between these two stages" is a
         | reading; it is the em dash above that means "there is nothing here
         | to measure".
         */
        $this->assertSame([0, 2, 2, 2, 1, 1], array_column(
            $this->props('charts', 'range=30')['funnel']['bands'], 'value',
        ));

        // assertEquals, not assertSame: a whole percentage comes back off the
        // wire as an int, and 0 there is a measured nought rather than the
        // absence the assertNulls above are about
        $this->assertEquals(0, $bands['details_shared']['drop'], 'both connected leads got details');
        $this->assertEquals(0, $bands['site_visit_done']['drop']);
        $this->assertEquals(50, $bands['in_discussion']['drop'], 'two visited, one went on');
        $this->assertEquals(0, $bands['booking_done']['drop']);
    }

    /* ---------------- sparklines ---------------- */

    /**
     * A bucket per day, every one of them, including the days nothing
     * happened. A quiet Tuesday is a 0 in the middle of the line — never a
     * missing point, never a shorter line.
     */
    public function test_sparklines_zero_fill_every_bucket_in_the_range(): void
    {
        $this->fixture();

        // 1–10 August: nothing at all happened in it
        $spark = $this->props('cards', 'from=2026-08-01&to=2026-08-10')['spark'];

        foreach (['total', 'today', 'visits', 'booked', 'lost', 'pending'] as $series) {
            $this->assertCount(10, $spark[$series], $series);
            $this->assertSame(array_fill(0, 10, 0), $spark[$series], $series);
        }

        $this->assertSame(10, $spark['buckets']);
        $this->assertFalse($spark['weekly']);
    }

    /**
     * A range with something in the middle and nothing at either end: the
     * zeros round the activity are the point, and so is the position of the
     * bucket that holds it.
     */
    public function test_a_sparkline_puts_its_activity_in_the_right_bucket(): void
    {
        $this->fixture();

        // 29 Aug – 2 Sep. Asha, Bina and Deepa were all created on 2 Sep, the
        // last of the five days; four empty buckets lead up to it.
        $spark = $this->props('cards', 'from=2026-08-29&to=2026-09-02')['spark'];

        $this->assertSame([0, 0, 0, 0, 3], $spark['total']);
        $this->assertSame($spark['total'], $spark['today'], 'both read the intake series');
        $this->assertSame([0, 0, 0, 0, 2], $spark['visits'], 'Asha and Chetan visited today');
        $this->assertSame([0, 0, 0, 0, 1], $spark['booked']);
    }

    /** Daily to 31 days, weekly from 32 — and never a bucket short either way. */
    public function test_the_buckets_turn_weekly_past_thirty_one_days(): void
    {
        $this->fixture();

        $daily = $this->props('cards', 'from=2026-08-03&to=2026-09-02')['spark'];

        $this->assertFalse($daily['weekly'], '31 days is still daily');
        $this->assertSame(31, $daily['buckets']);
        $this->assertCount(31, $daily['total']);

        $weekly = $this->props('cards', 'from=2026-08-02&to=2026-09-02')['spark'];

        $this->assertTrue($weekly['weekly'], '32 days goes weekly');
        // 32 days in weeks of seven is five buckets, the last one part-full
        $this->assertSame(5, $weekly['buckets']);
        $this->assertCount(5, $weekly['total']);
        $this->assertSame(3, array_sum($weekly['total']), 'no lead falls out of the bucketing');
    }

    /**
     * A one-day range is one bucket. The sparkline draws it as a level line
     * rather than a dot, but that is the component's business — what the
     * server owes it is a series of one, not an empty one.
     */
    public function test_a_one_day_range_is_a_single_bucket(): void
    {
        $this->fixture();

        $spark = $this->props('cards', 'range=today')['spark'];

        $this->assertSame(1, $spark['buckets']);
        $this->assertSame([3], $spark['total']);
    }

    /** The cross-filter narrows the series exactly as it narrows the figure. */
    public function test_a_cross_filter_narrows_the_sparklines_too(): void
    {
        $this->fixture();

        $all = $this->props('cards', 'from=2026-08-29&to=2026-09-02')['spark'];
        $one = $this->props('cards', 'from=2026-08-29&to=2026-09-02&source=facebook')['spark'];

        $this->assertSame([0, 0, 0, 0, 3], $all['total']);
        $this->assertSame([0, 0, 0, 0, 1], $one['total'], 'Bina alone came from Facebook');
        $this->assertCount(5, $one['total'], 'still zero-filled across the whole range');
    }

    /* ---------------- helpers ---------------- */

    /**
     * Four leads, and each is here to be told apart from the others by a
     * filter:
     *
     *   Asha    walk_in   created today       stands at site_visit_done, visited today
     *   Bina    facebook  created today       stands at connected
     *   Chetan  referral  created 40 days ago stands at booking_done, visited and booked today
     *   Deepa   walk_in   created today       stands at fresh, never called
     *
     * Plus five pending follow-ups so the two panels have something to lose:
     * three due today, two from before it.
     */
    private function fixture(): void
    {
        $asha = $this->lead('Asha', 'walk_in', now(), 'site_visit_done');
        $bina = $this->lead('Bina', 'facebook', now(), 'connected');
        $chetan = $this->lead('Chetan', 'referral', now()->subDays(40), 'booking_done');
        $deepa = $this->lead('Deepa', 'walk_in', now(), 'fresh');

        // history: completed to-dos carrying the stage each lead moved to
        $this->history($asha, 'connected', now()->subHours(4));
        $this->history($asha, 'details_shared', now()->subHours(3));
        $this->history($asha, 'site_visit_done', now()->subHours(2));
        $this->history($bina, 'connected', now()->subHours(2));
        $this->history($chetan, 'details_shared', now()->subHours(5));
        $this->history($chetan, 'site_visit_done', now()->subHours(4));
        $this->history($chetan, 'in_discussion', now()->subHours(3));
        $this->history($chetan, 'booking_done', now()->subHours(1));

        // outstanding work: three due today, two waiting longer
        $this->pending($asha, now()->setTime(16, 0));
        $this->pending($bina, now()->setTime(17, 0));
        $this->pending($deepa, now()->setTime(18, 0));
        $this->pending($asha, now()->subDays(3));
        $this->pending($chetan, now()->subDays(5));
    }

    private function lead(string $name, string $source, Carbon $createdAt, string $stage): Lead
    {
        $lead = Lead::create([
            'first_name' => $name, 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id, 'source' => $source,
            'stage' => $stage, 'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    /** One completed to-do: the row the event cards and the funnel count. */
    private function history(Lead $lead, string $stage, Carbon $at): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id, 'scheduled_at' => $at,
            'type' => 'call', 'status' => 'completed',
            'outcome_stage' => $stage, 'completed_at' => $at,
            'completed_by' => $this->admin->id,
        ]);
    }

    private function pending(Lead $lead, Carbon $at): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id, 'scheduled_at' => $at,
            'type' => 'call', 'status' => 'pending',
        ]);
    }

    /** One prop off a partial visit, so the sign-in notice is never burned. */
    private function props(string $only, string $query): array
    {
        return $this->actingAs($this->admin)->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => $only,
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get("/dashboard?reset=1&$query")->json("props.$only");
    }

    /** Cards, charts and both panels for one query, in one place. */
    private function page(string $query): array
    {
        return [
            'cards'     => $this->props('cards', $query),
            'charts'    => $this->props('charts', $query),
            'followUps' => $this->props('followUps', $query),
        ];
    }

    /** A stage chart's bars with the zeros dropped — what it actually draws. */
    private function nonZero(array $bars): array
    {
        return array_filter(collect($bars)->pluck('value', 'key')->all());
    }

    private function user(string $role): User
    {
        return User::create([
            'first_name' => ucfirst($role), 'last_name' => 'User',
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
