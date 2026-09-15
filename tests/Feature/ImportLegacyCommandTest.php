<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadImportRecord;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `import:legacy` against a small hand-built copy of old-data/.
 *
 * Rows 2, 3 and 4 share a mobile. 2 and 3 are also the same project, so 3
 * (the newest) survives and 2 is absorbed; 4 is another project and stands
 * alone. Rows 5 and 6 have no mobile in the same project — the unique index
 * has to let both in. Row 7 came through a broker and has a remark with no
 * date. All from one fixture source file ("sheet"); a second small fixture
 * proves two different source files sharing a bare row number never collide.
 */
class ImportLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'sheet';

    private string $directory;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-13 11:00'));

        $this->admin = User::factory()->role('admin')->create();
        $this->directory = storage_path('framework/testing/legacy-import-'.uniqid());
        $this->writeFixture();

        Cache::forever('illuminate:schedule:paused', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_dry_run_accounts_for_every_row_and_writes_nothing(): void
    {
        $before = $this->tableCounts();

        $this->artisan('import:legacy', ['--dry-run' => true, '--path' => $this->directory])
            ->expectsOutputToContain('6 / 6  ✓')
            ->expectsOutputToContain('DRY RUN rolled back — nothing written.')
            ->assertSuccessful();

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_a_dry_run_really_inserts_and_reports_real_counts_before_rolling_back(): void
    {
        $before = $this->tableCounts();

        // these are the real writeReferenceData()/writeGroups() counts, not a
        // plan-level preview — proof the transaction actually ran the inserts
        Artisan::call('import:legacy', ['--dry-run' => true, '--path' => $this->directory]);
        $output = Artisan::output();

        $this->assertSame(1, $this->numberAfter($output, 'Users created'));
        $this->assertSame(5, $this->numberAfter($output, 'Leads created'));
        $this->assertSame(1, $this->numberAfter($output, 'Leads absorbed'));
        $this->assertStringContainsString('Leads accounted for', $output);
        $this->assertSame(6, $this->numberAfter($output, 'Leads accounted for', 0));
        $this->assertSame(6, $this->numberAfter($output, 'Leads accounted for', 1));

        $this->assertSame($before, $this->tableCounts(), 'every insert the dry run made must be rolled back');
        $this->assertSame(0, Lead::count());
    }

    public function test_same_mobile_and_project_become_one_lead_that_keeps_the_absorbed_history(): void
    {
        $this->import()->assertSuccessful();

        $this->assertSame(5, Lead::count());
        $this->assertSame(6, LeadImportRecord::count(), 'every source row is recorded');

        $survivor = LeadImportRecord::where('source_row', 3)->firstOrFail()->lead;
        $this->assertSame($survivor->id, LeadImportRecord::where('source_row', 2)->value('lead_id'));
        $this->assertSame('absorbed', LeadImportRecord::where('source_row', 2)->value('outcome'));
        $this->assertSame('2024-03-01 00:00:00', (string) $survivor->created_at, 'the oldest of the group');
        $this->assertSame('site_visit_done', $survivor->stage, "rank 1's stage");
        $this->assertSame('2024-06-02 00:00:00', (string) $survivor->stage_changed_at);

        $todos = Todo::where('lead_id', $survivor->id)->get();
        $this->assertCount(3, $todos, "row 2's call, row 3's visit, and row 2's stage kept as history");
        $this->assertTrue($todos->contains('remarks', 'CNR'), "the absorbed row's own call moved across");
        $this->assertTrue($todos->contains('remarks', 'Liked the 3 BHK'));
        $absorbed = $todos->first(fn (Todo $t) => str_contains((string) $t->remarks, 'duplicate record: sheet row 2'));
        $this->assertSame('lost', $absorbed->outcome_stage);
        $this->assertSame('2024-03-01 00:00:00', (string) $absorbed->completed_at);

        $otherProject = LeadImportRecord::where('source_row', 4)->firstOrFail();
        $this->assertSame('created', $otherProject->outcome, 'same mobile, different project is its own lead');
        $this->assertNotSame($survivor->id, $otherProject->lead_id);
    }

    public function test_the_import_writes_history_the_consistency_check_accepts(): void
    {
        $this->import()->assertSuccessful();

        $this->artisan('crm:check-consistency')
            ->expectsOutputToContain('OK    Leads whose stage disagrees with their latest history row')
            ->expectsOutputToContain('OK    Duplicate history rows')
            ->expectsOutputToContain('OK    Terminal leads that still have a pending to-do')
            ->assertSuccessful();
    }

    public function test_no_follow_up_is_scheduled_and_open_leads_are_flagged_instead(): void
    {
        $this->import()->assertSuccessful();

        $this->assertSame(0, Todo::where('status', 'pending')->count());
        $this->assertSame(
            [3, 6],
            LeadImportRecord::where('awaiting_follow_up', true)->orderBy('source_row')->pluck('source_row')->all(),
        );
        $this->assertSame(2, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_it_writes_nothing_dated_today_and_fires_nothing(): void
    {
        $this->import()->assertSuccessful();

        $today = now()->toDateString();
        foreach (['leads' => ['created_at', 'updated_at', 'stage_changed_at', 'last_activity_at'],
            'todos' => ['scheduled_at', 'completed_at', 'created_at', 'updated_at'],
            'projects' => ['created_at'], 'users' => ['created_at'], 'channel_partners' => ['created_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $query = DB::table($table)->whereDate($column, $today);
                if ($table === 'users') {
                    $query->where('id', '!=', $this->admin->id);
                }
                $this->assertSame(0, $query->count(), "{$table}.{$column} carries today's date");
            }
        }

        foreach (['lead_activities', 'alerts', 'automation_logs', 'message_logs', 'jobs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} was written to");
        }
    }

    public function test_users_are_matched_by_email_and_created_inactive(): void
    {
        $this->import()->assertSuccessful();

        $riya = User::where('email', 'riya.gandhi@legacy.import')->firstOrFail();
        $this->assertFalse($riya->is_active);
        $this->assertSame('telecaller', $riya->role);
        $this->assertSame('approved', $riya->approval_status);

        $lead = LeadImportRecord::where('source_row', 5)->firstOrFail()->lead;
        $this->assertSame($riya->id, $lead->assigned_to);
    }

    public function test_a_second_run_leaves_an_existing_user_completely_alone(): void
    {
        $existing = User::factory()->create([
            'email' => 'riya.gandhi@legacy.import',
            'role' => 'admin',
            'is_active' => true,
            'first_name' => 'Someone', 'last_name' => 'Else',
        ]);

        $this->import()->assertSuccessful();

        $existing->refresh();
        $this->assertSame('admin', $existing->role, 'matched by email, then left alone — not overwritten');
        $this->assertTrue($existing->is_active);
        $this->assertSame($existing->id, LeadImportRecord::where('source_row', 5)->firstOrFail()->lead->assigned_to);
    }

    public function test_a_lead_with_no_assigned_user_fails_loudly_rather_than_defaulting_it(): void
    {
        $this->writeFixture(['assigned_to_name' => null]);
        $before = $this->tableCounts();

        $this->import()
            ->expectsOutputToContain('no assigned_to_name')
            ->assertFailed();

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_leads_with_no_mobile_all_import(): void
    {
        $this->import()->assertSuccessful();

        $this->assertSame(2, Lead::whereNull('mobile_number')->count());
    }

    public function test_a_remark_without_a_date_borrows_one_and_is_flagged(): void
    {
        $this->import()->assertSuccessful();

        $record = LeadImportRecord::where('source_row', 7)->firstOrFail();
        $this->assertContains('todo:follow_up_4:date_borrowed_from:created_at', $record->flags);
        $this->assertSame(1, Todo::where('lead_id', $record->lead_id)->where('remarks', 'Call not connect')
            ->where('scheduled_at', '2025-01-10 00:00:00')->count());
        $this->assertNotNull($record->lead->channel_partner_id);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->import()->assertSuccessful();
        $first = $this->tableCounts();

        $this->import()->expectsOutputToContain('Leads skipped as existing')->assertSuccessful();

        $this->assertSame($first, $this->tableCounts());
    }

    public function test_two_source_files_sharing_a_bare_row_number_never_collide(): void
    {
        // a second file whose row 3 is unrelated to sheet's row 3 — the same
        // bare row number, a different mobile, a different project entirely
        $second = storage_path('framework/testing/legacy-import-2-'.uniqid());
        File::ensureDirectoryExists($second);
        File::copy("{$this->directory}/projects.json", "{$second}/projects.json");
        File::copy("{$this->directory}/users.json", "{$second}/users.json");
        File::copy("{$this->directory}/sources.json", "{$second}/sources.json");
        File::copy("{$this->directory}/channel_partners.json", "{$second}/channel_partners.json");
        File::put("{$second}/leads.json", json_encode([
            $this->lead(3, '9700000099', 'skydeck', 'lost', '2024-08-01 00:00:00', null, sourceFile: 'sheet_two'),
        ]));
        File::put("{$second}/todos.json", json_encode([]));

        try {
            $this->import()->assertSuccessful();
            $this->artisan('import:legacy', ['--path' => $second])->assertSuccessful();

            $this->assertSame(6, Lead::count(), '5 from the first fixture + 1 from the second, not merged or clobbered');
            $this->assertNotNull(LeadImportRecord::where('source_file', self::SOURCE)->where('source_row', 3)->first());
            $this->assertNotNull(LeadImportRecord::where('source_file', 'sheet_two')->where('source_row', 3)->first());
        } finally {
            File::deleteDirectory($second);
        }
    }

    public function test_fresh_clears_the_previous_import_and_runs_it_again(): void
    {
        $this->import()->assertSuccessful();
        $first = $this->tableCounts();
        $firstIds = Lead::pluck('id')->all();

        $this->import(['--fresh' => true])->assertSuccessful();

        $this->assertSame($first, $this->tableCounts());
        $this->assertSame([], array_intersect($firstIds, Lead::pluck('id')->all()));
    }

    public function test_fresh_refuses_once_imported_leads_have_been_worked(): void
    {
        $this->import()->assertSuccessful();
        $lead = Lead::firstOrFail();
        Todo::create(['lead_id' => $lead->id, 'assigned_to' => $this->admin->id, 'scheduled_at' => '2026-09-20 10:00:00']);

        $this->import(['--fresh' => true])->assertFailed();
        $this->assertTrue(Lead::whereKey($lead->id)->exists());
    }

    public function test_an_unknown_stage_fails_loudly_and_writes_nothing(): void
    {
        $this->writeFixture(['stage' => 'mystery_stage']);
        $before = $this->tableCounts();

        $this->import()
            ->expectsOutputToContain("Stage 'mystery_stage' is used by the import but is not a row in lead_stages.")
            ->assertFailed();

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_an_unknown_source_fails_loudly_and_writes_nothing(): void
    {
        $this->writeFixture(['source' => 'carrier_pigeon']);
        $before = $this->tableCounts();

        $this->import()->expectsOutputToContain("Source 'carrier_pigeon'")->assertFailed();

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_the_real_run_refuses_while_the_scheduler_is_running(): void
    {
        Cache::forget('illuminate:schedule:paused');
        $before = $this->tableCounts();

        $this->import()->expectsOutputToContain('php artisan schedule:pause')->assertFailed();

        $this->assertSame($before, $this->tableCounts());
    }

    /* ---------------- helpers ---------------- */

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('import:legacy', $options + ['--path' => $this->directory]);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return collect(['leads', 'todos', 'lead_import_records', 'todo_import_records', 'users', 'projects', 'lead_sources', 'channel_partners'])
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    }

    /**
     * The Nth integer on the first line starting with $label, ignoring
     * however many spaces pairs() padded it with — that padding width shifts
     * whenever a longer label joins the same block, so matching on it
     * directly would be a test that breaks for reasons unrelated to itself.
     */
    private function numberAfter(string $output, string $label, int $nth = 0): int
    {
        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, $label)) {
                preg_match_all('/-?\d+/', substr($trimmed, strlen($label)), $matches);

                return (int) $matches[0][$nth];
            }
        }

        throw new \RuntimeException("No output line starts with '{$label}'");
    }

    /**
     * @param  array<string, mixed>  $row6Overrides  applied to row 6, to break the fixture on purpose
     */
    private function writeFixture(array $row6Overrides = []): void
    {
        $leads = [
            $this->lead(2, '9800000001', 'felicity', 'lost', '2024-03-01 00:00:00', 3, ['reason' => 'not_interested', 'last_activity_at' => '2024-03-05 00:00:00', 'updated_at' => '2024-03-05 00:00:00']),
            $this->lead(3, '9800000001', 'felicity', 'site_visit_done', '2024-06-01 00:00:00', 1, ['last_activity_at' => '2024-06-02 00:00:00', 'updated_at' => '2024-06-02 00:00:00']),
            $this->lead(4, '9800000001', 'skydeck', 'lost', '2024-04-01 00:00:00', 2, ['reason' => 'budget']),
            $this->lead(5, null, 'felicity', 'lost', '2024-05-01 00:00:00', null, ['reason' => 'other']),
            $this->lead(6, null, 'felicity', 'not_connected', '2024-05-02 00:00:00', null, $row6Overrides),
            $this->lead(7, '9800000007', 'felicity', 'lost', '2025-01-10 00:00:00', null, [
                'reason' => 'budget', 'source' => 'broker', 'broker_name' => 'Ravi Patel', 'channel_partner_key' => 'cp_001',
            ]),
        ];

        $todos = [
            $this->todo(2, 'follow_up_3', '2024-03-05 00:00:00', 'CNR'),
            $this->todo(3, 'first_visit', '2024-06-02 00:00:00', 'Liked the 3 BHK'),
            $this->todo(7, 'follow_up_4', null, 'Call not connect'),
        ];

        $write = fn (string $file, array $data) => File::put("{$this->directory}/{$file}", json_encode($data));

        File::ensureDirectoryExists($this->directory);
        $write('leads.json', $leads);
        $write('todos.json', $todos);
        $write('projects.json', [['key' => 'felicity', 'name' => 'Felicity'], ['key' => 'skydeck', 'name' => 'SkyDeck']]);
        $write('users.json', [[
            'name' => 'Riya Gandhi', 'proposed_first_name' => 'Riya', 'proposed_last_name' => 'Gandhi',
            'role' => 'telecaller', 'is_active' => false, 'email' => 'riya.gandhi@legacy.import',
        ]]);
        $write('sources.json', [
            ['source_value' => 'Facebook', 'proposed_key' => 'facebook'],
            ['source_value' => 'Broker', 'proposed_key' => 'broker'],
            ['source_value' => 'Housing Com', 'proposed_key' => 'housing_com'],
        ]);
        $write('channel_partners.json', [
            ['key' => 'cp_001', 'name' => 'Ravi Patel', 'name_key' => 'ravi patel', 'phone' => '9824199232', 'alt_phone' => null, 'lead_count' => 1],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lead(int $row, ?string $mobile, string $project, string $stage, string $createdAt, ?int $rank, array $overrides = [], string $sourceFile = self::SOURCE): array
    {
        $lead = $overrides + [
            '_row_number' => $row, '_source_file' => $sourceFile, '_import_key' => "{$sourceFile}:{$row}",
            'first_name' => "Lead{$row}", 'middle_name' => null, 'last_name' => 'Shah',
            'mobile_number' => $mobile, 'email' => null, 'project_key' => $project,
            'source' => 'facebook', 'external_id' => null, 'broker_name' => null, 'channel_partner_key' => null,
            'stage' => $stage, 'stage_changed_at' => null, 'not_connected_count' => 0,
            'assigned_to_name' => 'Riya Gandhi', 'requirement' => '3 BHK', 'reason' => null,
            'booked_unit' => null, 'booking_date' => null,
            'last_activity_at' => null, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            '_filled' => ['email'], '_flags' => [],
            '_duplicate_group' => $rank ? $mobile : null, '_duplicate_count' => $rank ? 3 : null, '_duplicate_rank' => $rank,
        ];
        $lead['_legacy'] = ['excel_row_number' => $row, 'secondary_stages' => "Sheet status of row {$row}", 'created_at' => $createdAt];

        return $lead;
    }

    /** @return array<string, mixed> */
    private function todo(int $row, string $column, ?string $date, string $remarks, string $sourceFile = self::SOURCE): array
    {
        $visit = str_contains($column, 'visit');

        return [
            'lead_row_number' => $row, '_lead_import_key' => "{$sourceFile}:{$row}",
            'assigned_to_name' => 'Riya Gandhi',
            'scheduled_at' => $date, 'type' => $visit ? 'site_visit' : 'call', 'status' => 'completed',
            'remarks' => $remarks, 'outcome_stage' => $visit ? 'site_visit_done' : 'connected',
            '_source_date_column' => $column,
        ];
    }
}
