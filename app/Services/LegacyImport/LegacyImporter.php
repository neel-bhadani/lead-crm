<?php

namespace App\Services\LegacyImport;

use App\Models\ChannelPartner;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes a LegacyImportPlan into the database.
 *
 * EVERY WRITE GOES THROUGH THE QUERY BUILDER (DB::table()->insert), never an
 * Eloquent model. That is how the import stays silent:
 *
 *   - no model event can fire, because no model is ever instantiated —
 *     ChannelPartner's `saving` hook, LeadSource's and LeadStage's `saved`
 *     cache flush and User's `updated` hook are all bypassed;
 *   - LeadFollowUpService is never called, so no automation trigger is
 *     queued, no RuleEngine::dispatch() runs, no WhatsApp job is dispatched;
 *   - LeadActivityRecorder is never called, so no timeline entry is written;
 *   - AlertService is never called, so no alert is raised.
 *
 * The tripwire in writeGroups() proves it per chunk: if any lead the chunk
 * just wrote has a row in lead_activities, alerts, automation_logs or
 * message_logs, the chunk is rolled back and the import stops.
 *
 * What the query builder does NOT do is stamp timestamps, which is the point:
 * every created_at, updated_at, stage_changed_at, scheduled_at and completed_at
 * is set explicitly from the JSON. Reference rows (projects, users, sources,
 * partners) are dated the day they first appear in the sheet, so nothing this
 * writes carries today's date.
 */
class LegacyImporter
{
    public const TRIPWIRE_TABLES = ['lead_activities', 'alerts', 'automation_logs', 'message_logs'];

    private const RECORD_TABLE = 'lead_import_records';

    /** @var array<string, int> project key => id */
    private array $projectIds = [];

    /** @var array<string, array{id: int, role: string, is_active: bool}> user name (or ADMIN) => user */
    private array $users = [];

    /** @var array<string, int> name_key => id */
    private array $partnerIds = [];

    /** @var array<int, true> source rows already recorded */
    private array $importedRows = [];

    /**
     * @var array{
     *     errors: list<string>, warnings: list<string>, blockers: list<string>,
     *     checks: array<string, string>,
     *     projects: array{create: list<string>, reuse: list<string>},
     *     users: array{create: list<string>, reuse: list<string>},
     *     sources: array{create: list<string>, reuse: list<string>},
     *     partners: array{create: int, reuse: int},
     *     groups_already_imported: int,
     * }|null
     */
    private ?array $preflight = null;

    public function __construct(private readonly LegacyImportPlan $plan) {}

    /* ====================================================================
     | Preflight — reads only
     ==================================================================== */

    /**
     * Everything the database has to say about the plan, without writing.
     *
     * `errors` stop both a dry run and a real one: the data would be imported
     * wrongly or not at all. `blockers` are steps to take before the real run
     * (migrate, pause the scheduler); a dry run reports them and carries on.
     *
     * @param  bool  $assumeFresh  report as if --fresh had already cleared the previous import
     * @return array<string, mixed>
     */
    public function preflight(bool $assumeFresh = false): array
    {
        $errors = [];
        $warnings = [];
        $blockers = [];
        $checks = [];

        $pending = $this->pendingMigrations();
        $checks['Pending migrations'] = $pending === [] ? 'none' : implode(', ', $pending);
        if ($pending !== []) {
            $blockers[] = 'Run `php artisan migrate` first: '.implode(', ', $pending);
        }

        $hasRecordTable = Schema::hasTable(self::RECORD_TABLE);
        $checks['lead_import_records table'] = $hasRecordTable ? 'exists' : 'missing (created by migration)';

        $mobileNullable = (bool) (collect(Schema::getColumns('leads'))->firstWhere('name', 'mobile_number')['nullable'] ?? false);
        $nullMobiles = count(array_filter(array_column(array_column($this->plan->groups, 'lead'), 'mobile_number'), 'is_null'));
        $checks['leads.mobile_number nullable'] = ($mobileNullable ? 'yes' : 'no (made nullable by migration)')." — {$nullMobiles} leads need it";

        $paused = (bool) Cache::get('illuminate:schedule:paused', false);
        $checks['Scheduler paused'] = $paused ? 'yes' : 'NO';
        if (! $paused) {
            $blockers[] = 'Pause the scheduler first: `php artisan schedule:pause`';
        }

        $checks['Timezone'] = (string) config('app.timezone');

        /* ---------------- admin fallback ---------------- */

        $admin = DB::table('users')->where('role', 'admin')->where('is_active', true)
            ->whereNull('deleted_at')->orderBy('id')->first(['id', 'first_name', 'last_name', 'role', 'is_active']);
        $needsAdmin = in_array(LegacyImportPlan::ADMIN, array_merge(
            array_column(array_column($this->plan->groups, 'lead'), 'owner_name'),
            array_column(array_merge(...array_column($this->plan->groups, 'todos') ?: [[]]), 'owner_name'),
        ), true);
        if ($admin) {
            $this->users[LegacyImportPlan::ADMIN] = ['id' => $admin->id, 'role' => $admin->role, 'is_active' => true];
            $checks['Admin for unassigned leads'] = "#{$admin->id} {$admin->first_name} {$admin->last_name}";
        } elseif ($needsAdmin) {
            $errors[] = 'Some leads have no assigned user and there is no active admin to give them to.';
        }

        /* ---------------- stages, sources, lost reasons ---------------- */

        $liveStages = CrmTaxonomy::stageKeys();
        foreach (array_diff($this->plan->stagesUsed, $liveStages) as $stage) {
            $errors[] = "Stage '{$stage}' is used by the import but is not a row in lead_stages.";
        }
        $checks['Stages used'] = implode(', ', $this->plan->stagesUsed);

        $liveSources = DB::table('lead_sources')->pluck('key')->all();
        $sources = ['create' => [], 'reuse' => []];
        foreach ($this->plan->sourcesUsed as $key) {
            if (in_array($key, $liveSources, true)) {
                $sources['reuse'][] = $key;
            } elseif (isset($this->plan->sources[$key])) {
                $sources['create'][] = $key;
            } else {
                $errors[] = "Source '{$key}' is neither a row in lead_sources nor proposed in sources.json.";
            }
        }

        $reasons = array_keys((array) config('crm.lost_reasons', []));
        foreach (array_diff($this->plan->lostReasonsUsed, $reasons) as $reason) {
            $errors[] = "Lost reason '{$reason}' is not a key in config('crm.lost_reasons').";
        }
        $checks['Lost reasons used'] = implode(', ', $this->plan->lostReasonsUsed);

        /* ---------------- projects, users, partners ---------------- */

        $projects = ['create' => [], 'reuse' => []];
        foreach ($this->plan->projects as $key => $project) {
            $id = DB::table('projects')->whereNull('deleted_at')->where('name', $project['name'])->orderBy('id')->value('id');
            if ($id) {
                $this->projectIds[$key] = (int) $id;
                $projects['reuse'][] = "{$project['name']} (#{$id})";
            } else {
                $projects['create'][] = $project['name'];
            }
        }

        $users = ['create' => [], 'reuse' => []];
        foreach ($this->plan->users as $name => $user) {
            $found = DB::table('users')->whereNull('deleted_at')->where('email', $user['email'])->first()
                ?? DB::table('users')->whereNull('deleted_at')
                    ->where('first_name', $user['first_name'])->where('last_name', $user['last_name'])
                    ->orderBy('id')->first();
            if ($found) {
                $this->users[$name] = ['id' => $found->id, 'role' => $found->role, 'is_active' => (bool) $found->is_active];
                $users['reuse'][] = "{$name} (#{$found->id}, ".($found->is_active ? 'ACTIVE — left as it is' : 'inactive').')';
            } else {
                $users['create'][] = "{$name} <{$user['email']}>";
            }
        }

        $partners = ['create' => 0, 'reuse' => 0];
        foreach (array_keys($this->plan->partners) as $nameKey) {
            $id = DB::table('channel_partners')->whereNull('deleted_at')
                ->where('name_key', $nameKey)->where('type', 'broker')->value('id');
            if ($id) {
                $this->partnerIds[$nameKey] = (int) $id;
                $partners['reuse']++;
            } else {
                $partners['create']++;
            }
            if (ChannelPartner::nameKey($this->plan->partners[$nameKey]['name']) !== $nameKey) {
                $errors[] = "Channel partner '{$this->plan->partners[$nameKey]['name']}': name_key in the JSON differs from ChannelPartner::nameKey().";
            }
        }

        /* ---------------- what was imported before ---------------- */

        $this->importedRows = [];
        if ($hasRecordTable && ! $assumeFresh) {
            $this->importedRows = array_fill_keys(
                DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)->pluck('source_row')->all(),
                true,
            );
        }

        $alreadyImported = 0;
        foreach ($this->plan->groups as $group) {
            $rows = array_column($group['records'], 'source_row');
            $done = count(array_filter($rows, fn (int $row) => isset($this->importedRows[$row])));
            if ($done === count($rows)) {
                $alreadyImported++;
            } elseif ($done > 0) {
                $errors[] = "Lead group of row {$group['survivor_row']} is partly imported ({$done} of ".count($rows)
                    .' rows). The JSON has changed since the last run — use --fresh.';
            }
        }

        /* ---------------- clashes with leads the import did not write ---------------- */

        $errors = array_merge($errors, $this->clashesWithExistingLeads());
        $errors = array_merge($errors, $this->columnLengthProblems());

        return $this->preflight = [
            'errors' => $errors,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'checks' => $checks,
            'projects' => $projects,
            'users' => $users,
            'sources' => $sources,
            'partners' => $partners,
            'groups_already_imported' => $alreadyImported,
        ];
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        $ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $files = array_map(fn (string $f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);

        return array_values(array_diff($files, $ran));
    }

    /**
     * A lead somebody entered by hand with the same mobile in the same project
     * would break the unique index halfway through a chunk. Found here instead.
     *
     * The index covers soft-deleted rows too, so they are counted.
     *
     * @return list<string>
     */
    private function clashesWithExistingLeads(): array
    {
        $wanted = [];
        foreach ($this->plan->groups as $group) {
            $lead = $group['lead'];
            if ($lead['mobile_number'] === null || isset($this->importedRows[$group['survivor_row']])) {
                continue;
            }
            if (isset($this->projectIds[$lead['project_key']])) {
                $wanted[$lead['mobile_number'].'|'.$this->projectIds[$lead['project_key']]] = $group['survivor_row'];
            }
        }

        // leads an earlier run wrote are skipped, or deleted first by --fresh
        $ours = Schema::hasTable(self::RECORD_TABLE)
            ? DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)->pluck('lead_id')->flip()->all()
            : [];

        $errors = [];
        foreach (array_chunk(array_keys($wanted), 500) as $chunk) {
            $mobiles = array_map(fn (string $k) => explode('|', $k)[0], $chunk);
            DB::table('leads')->whereIn('mobile_number', $mobiles)
                ->get(['id', 'mobile_number', 'project_id'])
                ->reject(fn ($lead) => isset($ours[$lead->id]))
                ->each(function ($lead) use ($wanted, &$errors) {
                    $key = $lead->mobile_number.'|'.$lead->project_id;
                    if (isset($wanted[$key])) {
                        $errors[] = "Row {$wanted[$key]}: lead #{$lead->id} already has mobile {$lead->mobile_number} in the same project.";
                    }
                });
        }

        return $errors;
    }

    /** @return list<string> */
    private function columnLengthProblems(): array
    {
        $limits = collect(Schema::getColumns('leads'))
            ->mapWithKeys(fn (array $c) => [$c['name'] => preg_match('/char\((\d+)\)/', $c['type'], $m) ? (int) $m[1] : null])
            ->filter();

        $errors = [];
        foreach ($this->plan->groups as $group) {
            foreach ($limits as $column => $limit) {
                $value = $group['lead'][$column] ?? null;
                if (is_string($value) && mb_strlen($value) > $limit) {
                    $errors[] = "Row {$group['survivor_row']}: {$column} is ".mb_strlen($value)." characters, the column holds {$limit}.";
                }
            }
        }

        return $errors;
    }

    /* ====================================================================
     | Writing
     ==================================================================== */

    /**
     * Projects, users, sources and channel partners, each only if missing.
     *
     * @return array{projects: int, users: int, sources: int, partners: int}
     */
    public function writeReferenceData(): array
    {
        $this->requirePreflight();
        $created = ['projects' => 0, 'users' => 0, 'sources' => 0, 'partners' => 0];

        DB::transaction(function () use (&$created) {
            foreach ($this->plan->projects as $key => $project) {
                if (isset($this->projectIds[$key])) {
                    continue;
                }
                $this->projectIds[$key] = DB::table('projects')->insertGetId([
                    'name' => $project['name'],
                    'type' => 'residential',
                    'is_active' => true,
                    'created_at' => $project['first_seen'],
                    'updated_at' => $project['first_seen'],
                ]);
                $created['projects']++;
            }

            foreach ($this->plan->users as $name => $user) {
                if (isset($this->users[$name])) {
                    continue;
                }
                $id = DB::table('users')->insertGetId([
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                    // nobody knows this, so nobody can sign in with it
                    'password' => Hash::make(Str::random(64)),
                    'role' => 'salesperson',
                    'is_active' => false,
                    'approval_status' => 'approved',
                    'created_at' => $user['first_seen'],
                    'updated_at' => $user['first_seen'],
                ]);
                $this->users[$name] = ['id' => $id, 'role' => 'salesperson', 'is_active' => false];
                $created['users']++;
            }

            $sortOrder = (int) DB::table('lead_sources')->max('sort_order');
            foreach ($this->preflight['sources']['create'] as $key) {
                if (DB::table('lead_sources')->where('key', $key)->exists()) {
                    continue;
                }
                $source = $this->plan->sources[$key];
                DB::table('lead_sources')->insert([
                    'key' => $key,
                    'label' => Str::limit($source['label'], 60, ''),
                    'sort_order' => $sortOrder += 10,
                    'is_system' => false,
                    'is_active' => true,
                    'created_at' => $source['first_seen'],
                    'updated_at' => $source['first_seen'],
                ]);
                $created['sources']++;
            }

            foreach ($this->plan->partners as $nameKey => $partner) {
                if (isset($this->partnerIds[$nameKey])) {
                    continue;
                }
                $this->partnerIds[$nameKey] = DB::table('channel_partners')->insertGetId([
                    'name' => $partner['name'],
                    'name_key' => $nameKey,
                    'type' => 'broker',
                    // NOT NULL, and a number nobody gave us is not invented
                    'phone' => $partner['phone'],
                    'alt_phone' => $partner['alt_phone'],
                    'is_active' => true,
                    'created_at' => $partner['first_seen'],
                    'updated_at' => $partner['first_seen'],
                ]);
                $created['partners']++;
            }
        });

        // DB::table() fires no LeadSource `saved` hook, so nothing else busts the cache
        CrmTaxonomy::flush();

        return $created;
    }

    /**
     * The leads, their todos and their import records, one transaction per
     * chunk of groups. A group — a lead and every row absorbed into it — is
     * never split across two chunks.
     *
     * @param  callable(int): void|null  $advance  called with the number of groups done
     * @return array{leads: int, absorbed: int, todos: int, skipped_groups: int}
     */
    public function writeGroups(string $batch, int $chunkSize, ?callable $advance = null): array
    {
        $this->requirePreflight();
        $stats = ['leads' => 0, 'absorbed' => 0, 'todos' => 0, 'skipped_groups' => 0];

        foreach (array_chunk($this->plan->groups, max(1, $chunkSize)) as $chunk) {
            DB::transaction(function () use ($chunk, $batch, &$stats) {
                $leadIds = [];

                foreach ($chunk as $group) {
                    if (isset($this->importedRows[$group['survivor_row']])) {
                        $stats['skipped_groups']++;

                        continue;
                    }

                    $leadId = DB::table('leads')->insertGetId($this->leadRow($group['lead']));
                    $leadIds[] = $leadId;

                    $todos = array_map(fn (array $todo) => $this->todoRow($leadId, $todo), $group['todos']);
                    foreach (array_chunk($todos, 500) as $rows) {
                        DB::table('todos')->insert($rows);
                    }

                    DB::table(self::RECORD_TABLE)->insert(array_map(
                        fn (array $record) => $this->recordRow($leadId, $record, $batch),
                        $group['records'],
                    ));

                    $stats['leads']++;
                    $stats['absorbed'] += count($group['records']) - 1;
                    $stats['todos'] += count($todos);
                }

                $this->tripwire($leadIds);
            });

            if ($advance) {
                $advance(count($chunk));
            }
        }

        return $stats;
    }

    /**
     * @param  list<int>  $leadIds
     */
    private function tripwire(array $leadIds): void
    {
        if ($leadIds === []) {
            return;
        }

        foreach (self::TRIPWIRE_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('lead_id', $leadIds)->exists()) {
                throw new RuntimeException("Tripwire: {$table} gained rows for imported leads. The chunk was rolled back.");
            }
        }

        $pending = DB::table('todos')->whereIn('lead_id', $leadIds)->where('status', 'pending')->exists();
        if ($pending) {
            throw new RuntimeException('Tripwire: a pending todo was written for an imported lead. The chunk was rolled back.');
        }
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed>
     */
    private function leadRow(array $lead): array
    {
        $owner = $this->users[$lead['owner_name']];

        $row = $lead;
        unset($row['project_key'], $row['owner_name'], $row['partner_name_key']);

        return $row + [
            'project_id' => $this->projectIds[$lead['project_key']],
            'channel_partner_id' => $lead['partner_name_key'] !== null ? $this->partnerIds[$lead['partner_name_key']] : null,
            'assigned_to' => $owner['id'],
            'assigned_role' => $owner['role'],
        ];
    }

    /**
     * @param  array<string, mixed>  $todo
     * @return array<string, mixed>
     */
    private function todoRow(int $leadId, array $todo): array
    {
        $owner = $this->users[$todo['owner_name']]['id'];

        return [
            'lead_id' => $leadId,
            'assigned_to' => $owner,
            'created_by' => null,
            'scheduled_at' => $todo['date'],
            'type' => $todo['type'],
            'status' => 'completed',
            'remarks' => $todo['remarks'],
            'outcome_stage' => $todo['outcome_stage'],
            'completed_at' => $todo['date'],
            'completed_by' => $owner,
            'rescheduled_from_id' => null,
            'created_at' => $todo['date'],
            'updated_at' => $todo['date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function recordRow(int $leadId, array $record, string $batch): array
    {
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'source_file' => $record['source_file'],
            'source_row' => $record['source_row'],
            'lead_id' => $leadId,
            'outcome' => $record['outcome'],
            'duplicate_group' => $record['duplicate_group'],
            'duplicate_rank' => $record['duplicate_rank'],
            'awaiting_follow_up' => $record['awaiting_follow_up'],
            'imported_todo_count' => $record['imported_todo_count'],
            'filled' => $json($record['filled']),
            'flags' => $json($record['flags']),
            'legacy' => $json($record['legacy']),
            'import_batch' => $batch,
        ];
    }

    private function requirePreflight(): void
    {
        if ($this->preflight === null) {
            throw new RuntimeException('Call preflight() before writing.');
        }
    }

    /* ====================================================================
     | --fresh
     ==================================================================== */

    /**
     * What --fresh would delete, and whether anybody has worked those leads
     * since: a todo or a timeline entry the import did not write.
     *
     * @return array{leads: int, records: int, imported_todos: int, todos: int, activities: int, worked_since: bool}
     */
    public static function freshSummary(): array
    {
        if (! Schema::hasTable(self::RECORD_TABLE)) {
            return ['leads' => 0, 'records' => 0, 'imported_todos' => 0, 'todos' => 0, 'activities' => 0, 'worked_since' => false];
        }

        $created = DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)->where('outcome', 'created');
        $leadIds = fn () => DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)
            ->where('outcome', 'created')->select('lead_id');

        $summary = [
            'leads' => (clone $created)->count(),
            'records' => DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)->count(),
            'imported_todos' => (int) (clone $created)->sum('imported_todo_count'),
            'todos' => DB::table('todos')->whereIn('lead_id', $leadIds())->count(),
            'activities' => DB::table('lead_activities')->whereIn('lead_id', $leadIds())->count(),
        ];
        $summary['worked_since'] = $summary['todos'] !== $summary['imported_todos'] || $summary['activities'] > 0;

        return $summary;
    }

    /**
     * Hard-delete every lead the import created, with its todos and records.
     * Projects, users, sources and partners stay: the next run reuses them.
     */
    public static function fresh(int $chunkSize = 500): int
    {
        if (! Schema::hasTable(self::RECORD_TABLE)) {
            return 0;
        }

        $deleted = 0;
        $ids = DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)
            ->where('outcome', 'created')->pluck('lead_id')->all();

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            DB::transaction(function () use ($chunk, &$deleted) {
                DB::table(self::RECORD_TABLE)->whereIn('lead_id', $chunk)->delete();
                DB::table('todos')->whereIn('lead_id', $chunk)->delete();
                $deleted += DB::table('leads')->whereIn('id', $chunk)->delete();
            });
        }

        return $deleted;
    }

    /* ====================================================================
     | After the run
     ==================================================================== */

    /**
     * The rules, checked against what is actually in the database.
     *
     * @param  list<string>  $terminalStages
     * @return array<string, mixed>
     */
    public static function verify(string $today, array $terminalStages): array
    {
        $imported = fn () => DB::table('leads')
            ->join(self::RECORD_TABLE.' as r', 'r.lead_id', '=', 'leads.id')
            ->where('r.source_file', LegacyImportPlan::SOURCE_FILE)
            ->where('r.outcome', 'created');
        $importedIds = fn () => DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)
            ->where('outcome', 'created')->select('lead_id');

        $leadDatesToday = $imported()->where(fn ($q) => $q
            ->whereDate('leads.created_at', $today)->orWhereDate('leads.updated_at', $today)
            ->orWhereDate('leads.stage_changed_at', $today)->orWhereDate('leads.last_activity_at', $today))->count();

        $todoDatesToday = DB::table('todos')->whereIn('lead_id', $importedIds())->where(fn ($q) => $q
            ->whereDate('scheduled_at', $today)->orWhereDate('completed_at', $today)
            ->orWhereDate('created_at', $today)->orWhereDate('updated_at', $today))->count();

        // created_at on each lead against the oldest created_at in its source rows
        $createdAtMismatches = 0;
        $expected = [];
        DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)
            ->orderBy('id')->select(['id', 'lead_id', 'legacy'])
            ->chunkById(1000, function ($records) use (&$expected) {
                foreach ($records as $record) {
                    $created = json_decode($record->legacy, true)['created_at'];
                    $expected[$record->lead_id] = min($expected[$record->lead_id] ?? $created, $created);
                }
            });
        $imported()->select(['leads.id', 'leads.created_at'])->orderBy('leads.id')
            ->chunk(1000, function ($leads) use ($expected, &$createdAtMismatches) {
                foreach ($leads as $lead) {
                    if ((string) $lead->created_at !== ($expected[$lead->id] ?? null)) {
                        $createdAtMismatches++;
                    }
                }
            });

        $byLabel = fn (string $column, ?string $join = null) => $imported()
            ->when($join === 'projects', fn ($q) => $q->join('projects as p', 'p.id', '=', 'leads.project_id'))
            ->selectRaw("{$column} as label, count(*) as total")
            ->groupBy($column)->orderByDesc('total')
            ->pluck('total', 'label')->all();

        $names = DB::table('users')->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim("{$u->first_name} {$u->last_name}")." (#{$u->id})"]);
        $byUser = collect($byLabel('leads.assigned_to'))
            ->mapWithKeys(fn ($total, $id) => [$names[$id] ?? "#{$id}" => $total])->all();

        return [
            'records' => DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)->count(),
            'created' => $imported()->count(),
            'absorbed' => DB::table(self::RECORD_TABLE)->where('source_file', LegacyImportPlan::SOURCE_FILE)->where('outcome', 'absorbed')->count(),
            'todos' => DB::table('todos')->whereIn('lead_id', $importedIds())->count(),
            'oldest_created_at' => $imported()->min('leads.created_at'),
            'newest_created_at' => $imported()->max('leads.created_at'),
            'lead_dates_today' => $leadDatesToday,
            'todo_dates_today' => $todoDatesToday,
            'created_at_differs_from_json' => $createdAtMismatches,
            'terminal_with_pending' => $imported()->whereIn('leads.stage', $terminalStages)
                ->whereExists(fn ($q) => $q->from('todos')->whereColumn('todos.lead_id', 'leads.id')->where('status', 'pending'))->count(),
            'leads_with_more_than_one_pending' => DB::table('todos')->where('status', 'pending')
                ->select('lead_id')->groupBy('lead_id')->havingRaw('count(*) > 1')->get()->count(),
            'open_without_pending' => $imported()->whereNotIn('leads.stage', $terminalStages)
                ->whereNotExists(fn ($q) => $q->from('todos')->whereColumn('todos.lead_id', 'leads.id')->where('status', 'pending'))->count(),
            'activities_on_imported' => DB::table('lead_activities')->whereIn('lead_id', $importedIds())->count(),
            'alerts_on_imported' => DB::table('alerts')->whereIn('lead_id', $importedIds())->count(),
            'by_stage' => $byLabel('leads.stage'),
            'by_source' => $byLabel('leads.source'),
            'by_project' => $byLabel('p.name', 'projects'),
            'by_user' => $byUser,
        ];
    }
}
