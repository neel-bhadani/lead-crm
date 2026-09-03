<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The stage chips, and the one thing about them that is easy to get wrong.
 *
 * A chip has to answer "how would this list break down by stage" — this list,
 * with every other filter applied, and without the stage filter itself. Get
 * that last part wrong and selecting "Lost" leaves nine chips reading zero and
 * one reading the whole list, which is worse than no counts at all.
 *
 * @see \App\Http\Controllers\LeadController::stageCounts()
 */
class LeadStageChipsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $telecaller;
    private Project $green;
    private Project $skyline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin      = $this->user('admin');
        $this->telecaller = $this->user('telecaller');
        $this->green      = Project::create(['name' => 'Green Court']);
        $this->skyline    = Project::create(['name' => 'Skyline Residency']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- the row adds up ---------------- */

    /** Every configured stage has a chip, in config order, zeros included. */
    public function test_the_chips_are_zero_filled_across_every_stage(): void
    {
        $counts = $this->chips();

        $this->assertSame(array_keys(config('crm.stages')), array_column($counts['bars'], 'key'));
        $this->assertSame(array_values(config('crm.stages')), array_column($counts['bars'], 'label'));
        $this->assertSame(0, $counts['total']);
    }

    /** "All" is the chips, summed. The two cannot disagree. */
    public function test_the_chips_sum_to_the_all_count(): void
    {
        $this->leads($this->green, 'fresh', 3);
        $this->leads($this->green, 'lost', 2);
        $this->leads($this->skyline, 'booking_done', 4);

        $counts = $this->chips();

        $this->assertSame(9, $counts['total']);
        $this->assertSame(9, array_sum(array_column($counts['bars'], 'value')));
        $this->assertSame(9, Lead::count());
    }

    /* ---------------- the counts follow the other filters ---------------- */

    /** A project narrows the whole row. */
    public function test_another_filter_changes_the_counts(): void
    {
        $this->leads($this->green, 'fresh', 3);
        $this->leads($this->green, 'lost', 2);
        $this->leads($this->skyline, 'fresh', 6);

        $this->assertSame(11, $this->chips()['total']);

        $green = $this->chips(['project_id' => $this->green->id]);

        $this->assertSame(5, $green['total']);
        $this->assertSame(3, $this->bar($green, 'fresh'));
        $this->assertSame(2, $this->bar($green, 'lost'));
        $this->assertSame(0, $this->bar($green, 'booking_done'));
    }

    /**
     * The whole point. Selecting a stage filters the table and leaves every
     * chip exactly where it was — including the one that was clicked.
     */
    public function test_the_stage_filter_does_not_change_the_counts(): void
    {
        $this->leads($this->green, 'fresh', 3);
        $this->leads($this->green, 'lost', 2);
        $this->leads($this->skyline, 'fresh', 6);

        $before = $this->chips(['project_id' => $this->green->id]);

        foreach (['fresh', 'lost', 'booking_done'] as $stage) {
            $after = $this->chips(['project_id' => $this->green->id, 'stage' => $stage]);

            $this->assertSame($before, $after, "selecting $stage moved the chips");
        }

        // ...while the table itself really is filtered
        $this->assertSame(5, $this->rows(['project_id' => $this->green->id]));
        $this->assertSame(3, $this->rows(['project_id' => $this->green->id, 'stage' => 'fresh']));
        $this->assertSame(2, $this->rows(['project_id' => $this->green->id, 'stage' => 'lost']));
        $this->assertSame(0, $this->rows(['project_id' => $this->green->id, 'stage' => 'booking_done']));
    }

    /** Search narrows the counts too — every filter but stage does. */
    public function test_search_narrows_the_counts(): void
    {
        $this->leads($this->green, 'fresh', 2);
        $this->lead($this->green, 'lost', now(), ['first_name' => 'Zarina']);

        $this->assertSame(3, $this->chips()['total']);

        $found = $this->chips(['search' => 'Zarina']);

        $this->assertSame(1, $found['total']);
        $this->assertSame(1, $this->bar($found, 'lost'));
        $this->assertSame(0, $this->bar($found, 'fresh'));
    }

    /* ---------------- who is counted ---------------- */

    /** A telecaller's chips count a telecaller's leads and nobody else's. */
    public function test_a_telecaller_only_counts_their_own(): void
    {
        $this->leads($this->green, 'fresh', 4);                          // the admin's
        $this->leads($this->green, 'lost', 3, $this->telecaller);

        $this->assertSame(7, $this->chips()['total'], 'the admin sees all of them');

        $mine = $this->chips([], $this->telecaller);

        $this->assertSame(3, $mine['total']);
        $this->assertSame(3, $this->bar($mine, 'lost'));
        $this->assertSame(0, $this->bar($mine, 'fresh'), "another owner's leads must not be counted");
    }

    /** A soft-deleted lead leaves the chips with everything else. */
    public function test_a_deleted_lead_leaves_the_counts(): void
    {
        $this->leads($this->green, 'fresh', 3);

        $this->assertSame(3, $this->chips()['total']);

        Lead::first()->delete();

        $this->assertSame(2, $this->chips()['total']);
        $this->assertSame(2, $this->bar($this->chips(), 'fresh'));
    }

    /* ---------------- the date filter ---------------- */

    /**
     * Seven whole days ending today, today counted as one of them. A bare
     * subDays() keeps the current clock time and drops the earliest morning.
     */
    public function test_the_seven_day_range_covers_seven_whole_days(): void
    {
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-27 00:30'));   // first minute of day one
        $this->lead($this->green, 'fresh', Carbon::parse('2026-09-02 23:30'));   // last minute of today
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-26 23:30'));   // one minute too early

        $this->assertSame(2, $this->chips(['range' => '7'])['total']);
        $this->assertSame(3, $this->chips(['range' => '30'])['total']);
        $this->assertSame(3, $this->chips()['total'], 'all time is the default');
    }

    /** Today runs 00:00 to 23:59:59, not from the moment of the request. */
    public function test_today_covers_the_whole_day(): void
    {
        $this->lead($this->green, 'fresh', Carbon::parse('2026-09-02 07:00'));
        $this->lead($this->green, 'lost', Carbon::parse('2026-09-02 20:00'));
        $this->lead($this->green, 'fresh', Carbon::parse('2026-09-01 23:59'));

        $this->assertSame(2, $this->chips(['range' => 'today'])['total']);
    }

    /** A custom pair includes both end days in full. */
    public function test_a_custom_range_includes_both_end_days(): void
    {
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-10 00:10'));
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-12 23:50'));
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-09 23:50'));
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-13 00:10'));

        $this->assertSame(2, $this->chips(['from' => '2026-08-10', 'to' => '2026-08-12'])['total']);
    }

    /** A pair outranks a preset, and takes it out of the session with it. */
    public function test_a_custom_pair_replaces_a_preset(): void
    {
        $this->lead($this->green, 'fresh', Carbon::parse('2026-08-10 09:00'));
        $this->lead($this->green, 'fresh', Carbon::parse('2026-09-02 09:00'));

        $props = $this->props(['range' => '7', 'from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(1, $props['stageCounts']['total']);
        $this->assertSame('custom', $props['filters']['range'], 'the control reads Custom');
        $this->assertArrayNotHasKey('range', session('filters.leads'));
    }

    /** A backwards pair, or one ending in the future, is dropped on the way in. */
    public function test_an_impossible_custom_pair_falls_back_to_all_time(): void
    {
        $this->leads($this->green, 'fresh', 3);

        $this->assertSame(3, $this->chips(['from' => '2026-08-20', 'to' => '2026-08-10'])['total']);
        $this->assertSame(3, $this->chips(['from' => '2026-08-01', 'to' => '2099-01-01'])['total']);
        // and neither is left sitting in the session to be rejected again
        $this->assertArrayNotHasKey('from', session('filters.leads'));
    }

    /** Half a pair is not a range. */
    public function test_a_lone_date_is_ignored(): void
    {
        $this->leads($this->green, 'fresh', 3);

        $this->assertSame(3, $this->chips(['from' => '2026-09-02'])['total']);
        $this->assertArrayNotHasKey('from', session('filters.leads'));
    }

    /* ---------------- clearing ---------------- */

    /** Clear puts the stage chip back to All and the dates back to All time. */
    public function test_clear_resets_the_stage_and_the_dates(): void
    {
        $this->leads($this->green, 'fresh', 3);
        $this->leads($this->skyline, 'lost', 2);

        $this->props(['stage' => 'fresh', 'project_id' => $this->green->id, 'range' => '7']);

        $this->assertSame(['stage' => 'fresh', 'project_id' => (string) $this->green->id, 'range' => '7'],
            session('filters.leads'));

        // Clear sends reset=1 and nothing else
        $props = $this->props([]);

        $this->assertSame([], session('filters.leads'));
        $this->assertSame(5, $props['stageCounts']['total']);
        $this->assertSame(5, $props['leads']['total']);
        $this->assertSame('', $props['filters']['range']);
        $this->assertArrayNotHasKey('stage', $props['filters']);
    }

    /* ---------------- helpers ---------------- */

    private function props(array $query, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)
            ->get('/leads?' . http_build_query($query + ['reset' => 1]), [
                'X-Inertia'         => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            ])
            ->json('props');
    }

    private function chips(array $query = [], ?User $as = null): array
    {
        return $this->props($query, $as)['stageCounts'];
    }

    private function rows(array $query = [], ?User $as = null): int
    {
        return $this->props($query, $as)['leads']['total'];
    }

    private function bar(array $counts, string $stage): int
    {
        return collect($counts['bars'])->firstWhere('key', $stage)['value'];
    }

    private function leads(Project $project, string $stage, int $n, ?User $owner = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->lead($project, $stage, now(), [], $owner);
        }
    }

    private function lead(
        Project $project,
        string $stage,
        Carbon $createdAt,
        array $overrides = [],
        ?User $owner = null,
    ): Lead {
        $lead = Lead::create($overrides + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $project->id,
            'source'        => 'walk_in',
            'stage'         => $stage,
            'assigned_to'   => ($owner ?? $this->admin)->id,
            'created_by'    => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
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
