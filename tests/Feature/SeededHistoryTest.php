<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo data has to obey the same rules the application does, or the
 * dashboard looks broken when it is only the seed that is.
 *
 * The seeder used to step the cursor forward by a random interval and then
 * yank it back to "some time in the last 40 hours" whenever it overshot now.
 * That left history running backwards — 14:12, 22:12, 05:12, 13:12 — so the
 * newest row was not the last stage the lead reached, and leads.stage looked
 * like it disagreed with its own history.
 */
class SeededHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    /** So the per-project round robin has a team from the first lead, with no setup. */
    public function test_both_salespeople_are_on_every_project(): void
    {
        $salespeople = User::where('role', 'salesperson')->pluck('id')->sort()->values()->all();

        $this->assertCount(2, $salespeople);
        $this->assertSame(3, Project::count());

        Project::all()->each(fn (Project $p) => $this->assertSame(
            $salespeople,
            $p->salespeople()->pluck('users.id')->sort()->values()->all(),
            $p->name,
        ));
    }

    public function test_every_leads_history_runs_forwards(): void
    {
        $offenders = [];

        foreach (Lead::all(['id']) as $lead) {
            $stamps = Todo::where('lead_id', $lead->id)
                ->whereNotNull('outcome_stage')
                ->orderBy('id')
                ->pluck('completed_at');

            $sorted = $stamps->sort()->values();

            if ($stamps->map->timestamp->all() !== $sorted->map->timestamp->all()) {
                $offenders[] = $lead->id;
            }
        }

        $this->assertSame([], $offenders, 'history rows are out of chronological order');
    }

    public function test_no_history_row_is_in_the_future(): void
    {
        $this->assertSame(0, Todo::whereNotNull('completed_at')->where('completed_at', '>', now())->count());
    }

    public function test_every_leads_stage_matches_its_latest_history_row(): void
    {
        $mismatched = [];

        foreach (Lead::all(['id', 'stage']) as $lead) {
            $latest = Todo::where('lead_id', $lead->id)
                ->whereNotNull('outcome_stage')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('outcome_stage');

            if ($latest && $latest !== $lead->stage) {
                $mismatched[] = "{$lead->id}: stage={$lead->stage} history=$latest";
            }
        }

        $this->assertSame([], $mismatched);
    }

    public function test_the_seed_writes_no_duplicate_history_rows(): void
    {
        $dupes = Todo::whereNotNull('outcome_stage')
            ->selectRaw('lead_id, outcome_stage, completed_at, count(*) as total')
            ->groupBy('lead_id', 'outcome_stage', 'completed_at')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $dupes);
    }

    public function test_the_seed_honours_the_pending_todo_rule(): void
    {
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        foreach (Lead::open()->get(['id']) as $lead) {
            $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());
        }

        foreach (Lead::whereIn('stage', config('crm.terminal_stages'))->get(['id']) as $lead) {
            $this->assertSame(0, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());
        }
    }

    public function test_the_consistency_command_reports_the_seed_as_clean(): void
    {
        $this->artisan('crm:check-consistency')
            ->expectsOutputToContain('No inconsistencies found.')
            ->assertSuccessful();
    }

    /**
     * Distinct leads and raw rows agree on the seed, which is the same as
     * saying no seeded lead reaches a stage twice in one day. Every count on
     * the page is COUNT(DISTINCT lead_id), so the seed disagreeing with itself
     * here is what would make a hand check of the data read differently from
     * the dashboard.
     */
    public function test_a_single_booking_gives_the_card_and_the_chart_the_same_number(): void
    {
        $from = today()->startOfDay();
        $to = now();

        foreach (['booking_done', 'lost', 'site_visit_done'] as $stage) {
            $card = Todo::where('outcome_stage', $stage)
                ->whereBetween('completed_at', [$from, $to])
                ->distinct('lead_id')->count('lead_id');

            $chart = Todo::where('outcome_stage', $stage)
                ->whereBetween('completed_at', [$from, $to])
                ->count();

            $this->assertSame($card, $chart, "$stage: a lead reached it twice in one day");
        }
    }
}
