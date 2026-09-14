<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\LegacyImportPlan;
use App\Support\CrmTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Seeds the Master Sheet, as restructured in master-data/db/, into the CRM.
 *
 * Plan in memory (LegacyImportPlan), check against the database
 * (LegacyImporter::preflight), then write in chunks through the query builder
 * so that no model event, automation trigger, alert, message or timeline
 * entry fires — see LegacyImporter for exactly how.
 *
 * The real run refuses to start while the scheduler is running: the hourly
 * `automation:run` reads leads, and half an import is not something it
 * should be reading. `php artisan schedule:pause` first, `schedule:resume`
 * after.
 *
 * Idempotent: every source row is recorded in lead_import_records under a
 * unique (source_file, source_row), and a second run skips whatever is there.
 */
class ImportLegacy extends Command
{
    protected $signature = 'import:legacy
                            {--dry-run : Validate everything and report, writing nothing}
                            {--fresh : Delete the leads, todos and records a previous run imported, first}
                            {--force : With --fresh, delete even if those leads have been worked since}
                            {--awaiting : List imported open leads that still have no pending follow-up}
                            {--path=master-data/db : Directory holding the restructured JSON}
                            {--chunk=250 : Leads (with their absorbed rows) per transaction}';

    protected $description = 'Import the legacy Master Sheet leads from master-data/db/';

    public function handle(): int
    {
        if (config('app.timezone') !== 'Asia/Kolkata') {
            $this->error('app.timezone must be Asia/Kolkata; it is '.config('app.timezone').'.');

            return self::FAILURE;
        }

        if ($this->option('awaiting')) {
            return $this->listAwaiting();
        }

        $dryRun = (bool) $this->option('dry-run');
        $today = now()->toDateString();
        $path = $this->resolvePath((string) $this->option('path'));

        $this->line($dryRun ? '<options=bold>DRY RUN — nothing will be written</>' : '<options=bold>IMPORT</>');
        $this->line("Input: {$path}   Today: {$today} (".config('app.timezone').')');
        $this->newLine();

        $fresh = $this->option('fresh') ? LegacyImporter::freshSummary() : null;
        if ($fresh !== null) {
            $this->section('--fresh would delete');
            $this->pairs([
                'Imported leads' => $fresh['leads'],
                'Import records' => $fresh['records'],
                'Todos on those leads' => "{$fresh['todos']} (the import wrote {$fresh['imported_todos']})",
                'Timeline entries on those leads' => $fresh['activities'],
            ]);
            if ($fresh['worked_since'] && ! $this->option('force')) {
                $this->error('Those leads have been worked since the import. Re-run with --force to delete them anyway.');

                return self::FAILURE;
            }
        }

        $plan = LegacyImportPlan::fromDirectory($path, $today, CrmTaxonomy::terminalStages());
        if (! $plan->isValid()) {
            return $this->refuse($plan->errors);
        }

        $importer = new LegacyImporter($plan);
        $preflight = $importer->preflight(assumeFresh: $fresh !== null);

        $this->report($plan, $preflight);

        if ($preflight['errors'] !== []) {
            return $this->refuse($preflight['errors']);
        }

        if ($dryRun) {
            $this->section('Before the real run');
            $preflight['blockers'] === []
                ? $this->info('  Nothing — the real run can go ahead.')
                : collect($preflight['blockers'])->each(fn ($b) => $this->warn("  - {$b}"));
            $this->newLine();
            $this->info('Dry run complete. Nothing was written.');

            return self::SUCCESS;
        }

        if ($preflight['blockers'] !== []) {
            return $this->refuse($preflight['blockers']);
        }

        return $this->write($plan, $importer, $fresh !== null, $today);
    }

    private function write(LegacyImportPlan $plan, LegacyImporter $importer, bool $fresh, string $today): int
    {
        $jobsBefore = DB::table('jobs')->count();

        try {
            if ($fresh) {
                $deleted = LegacyImporter::fresh();
                $this->info("--fresh: deleted {$deleted} previously imported leads.");
                $importer->preflight(assumeFresh: true);
            }

            $reference = $importer->writeReferenceData();
            $this->section('Reference data written');
            $this->pairs($reference);

            $this->section('Leads');
            $bar = $this->output->createProgressBar(count($plan->groups));
            $bar->start();
            $stats = $importer->writeGroups(
                (string) Str::uuid(),
                (int) $this->option('chunk'),
                fn (int $done) => $bar->advance($done),
            );
            $bar->finish();
            $this->newLine(2);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Import stopped: '.$e->getMessage());
            $this->warn('Every finished chunk is committed; the failing chunk was rolled back. Re-running skips what is in.');
            $this->remindScheduler();

            return self::FAILURE;
        }

        $this->pairs([
            'Leads created' => $stats['leads'],
            'Rows absorbed into them' => $stats['absorbed'],
            'Todos created' => $stats['todos'],
            'Lead groups already imported, skipped' => $stats['skipped_groups'],
            'Queued jobs added during the import' => DB::table('jobs')->count() - $jobsBefore,
        ]);

        $verify = LegacyImporter::verify($today, CrmTaxonomy::terminalStages());
        $this->section('Verification');
        $this->pairs([
            'Import records (must equal input rows)' => "{$verify['records']} / {$plan->inputRows}",
            'Leads created / absorbed' => "{$verify['created']} / {$verify['absorbed']}",
            'Todos on imported leads' => $verify['todos'],
            'Oldest / newest created_at' => "{$verify['oldest_created_at']} / {$verify['newest_created_at']}",
            'Lead dates equal to today' => $verify['lead_dates_today'],
            'Todo dates equal to today' => $verify['todo_dates_today'],
            'created_at differing from the JSON' => $verify['created_at_differs_from_json'],
            'Terminal leads with a pending follow-up' => $verify['terminal_with_pending'],
            'Leads with more than one pending follow-up' => $verify['leads_with_more_than_one_pending'],
            'Open imported leads with no follow-up (flagged)' => $verify['open_without_pending'],
            'Timeline entries / alerts on imported leads' => "{$verify['activities_on_imported']} / {$verify['alerts_on_imported']}",
        ]);
        foreach (['by_stage' => 'Stage', 'by_source' => 'Source', 'by_project' => 'Project', 'by_user' => 'Assigned to'] as $key => $label) {
            $this->section("Imported leads by {$label}");
            $this->pairs($verify[$key]);
        }

        $this->remindScheduler();

        return self::SUCCESS;
    }

    /* ---------------- report ---------------- */

    /**
     * @param  array<string, mixed>  $preflight
     */
    private function report(LegacyImportPlan $plan, array $preflight): void
    {
        $leads = array_column($plan->groups, 'lead');
        $records = $plan->records();
        $absorbed = count($records) - count($plan->groups);
        $todos = array_sum(array_map('count', array_column($plan->groups, 'todos')));

        $this->section('Prerequisites');
        $this->pairs($preflight['checks']);

        $this->section('Reference data');
        $this->pairs([
            'Projects to create' => $this->listOrNone($preflight['projects']['create']),
            'Projects to reuse' => $this->listOrNone($preflight['projects']['reuse']),
            'Users to create (inactive)' => $this->listOrNone($preflight['users']['create']),
            'Users to reuse' => $this->listOrNone($preflight['users']['reuse']),
            'Sources to add to lead_sources' => $this->listOrNone($preflight['sources']['create']),
            'Sources already there' => $this->listOrNone($preflight['sources']['reuse']),
            'Channel partners to create / reuse' => "{$preflight['partners']['create']} / {$preflight['partners']['reuse']}"
                ." (from {$plan->inputPartnerPairs} name+number pairs)",
        ]);

        $this->section('Rows');
        $this->pairs([
            'Input rows (leads.json)' => $plan->inputRows,
            'Leads to create' => count($plan->groups),
            'Rows absorbed (same mobile + same project)' => $absorbed,
            'Accounted for' => (count($plan->groups) + $absorbed).' / '.$plan->inputRows
                .((count($plan->groups) + $absorbed) === $plan->inputRows ? '  ✓' : '  ✗'),
            'Lead groups already imported (will be skipped)' => $preflight['groups_already_imported'],
            'Leads with no mobile (stored as null)' => count(array_filter(array_column($leads, 'mobile_number'), 'is_null')),
            'Leads with no assigned user (to the admin)' => count(array_filter($leads, fn ($l) => $l['owner_name'] === LegacyImportPlan::ADMIN)),
            'Leads with no project (to "Unassigned")' => count(array_filter($leads, fn ($l) => $l['project_key'] === LegacyImportPlan::UNASSIGNED_PROJECT_KEY)),
            'Leads linked to a channel partner' => count(array_filter(array_column($leads, 'partner_name_key'))),
        ]);

        $this->section('Todos (all completed; no pending follow-up is created)');
        $this->pairs([
            'Todos in todos.json' => $plan->inputTodos,
            'Visits (outcome site_visit_done)' => $plan->todoCounts['visits'],
            'Follow-up calls (no outcome stage)' => $plan->todoCounts['follow_ups'],
            '  of which date borrowed (no date in the sheet)' => $plan->todoCounts['date_borrowed'],
            'Stages of absorbed rows, kept as history' => $plan->todoCounts['absorbed_stage'],
            'Final "imported at this stage" history rows' => $plan->todoCounts['final_stage'],
            'Same stage twice on one day — outcome dropped, remark kept' => $plan->todoCounts['stage_already_recorded_same_day'],
            'Todos to create' => $todos,
            'Pending todos to create' => 0,
        ]);

        $open = array_filter($leads, fn ($l) => ! in_array($l['stage'], CrmTaxonomy::terminalStages(), true));
        $this->section('Open leads flagged awaiting_follow_up (no follow-up scheduled)');
        $this->pairs(['Total' => count($open)] + $this->countBy($open, 'owner_name'));

        $this->section('Leads to create, by stage');
        $this->pairs($this->countBy($leads, 'stage'));
        $this->section('Leads to create, by source');
        $this->pairs($this->countBy($leads, 'source'));
        $this->section('Leads to create, by project');
        $this->pairs(collect($this->countBy($leads, 'project_key'))
            ->mapWithKeys(fn ($n, $key) => [($plan->projects[$key]['name'] ?? $key) => $n])->all());
        $this->section('Leads to create, by assigned user');
        $this->pairs($this->countBy($leads, 'owner_name'));

        $flags = [];
        foreach ($records as $record) {
            foreach (array_unique(array_map([$this, 'flagName'], $record['flags'])) as $flag) {
                $flags[$flag] = ($flags[$flag] ?? 0) + 1;
            }
        }
        arsort($flags);
        $this->section('Flagged rows, by reason (a row can carry several)');
        $this->pairs(['Rows with at least one flag' => count(array_filter($records, fn ($r) => $r['flags'] !== []))] + $flags);

        $created = array_column($leads, 'created_at');
        $this->section('Dates');
        $this->pairs([
            'Oldest lead created_at' => min($created),
            'Newest lead created_at' => max($created),
            'Dates equal to today' => '0 (the plan refuses any)',
            'Completed todos dated after today (kept as written)' => $flags['todo:dated_in_the_future'] ?? 0,
        ]);

        if ($preflight['warnings'] !== [] || $plan->warnings !== []) {
            $this->section('Warnings');
            collect(array_merge($plan->warnings, $preflight['warnings']))->each(fn ($w) => $this->warn("  - {$w}"));
        }
    }

    private function flagName(string $flag): string
    {
        $parts = explode(':', $flag);

        return $parts[0] === 'todo' ? 'todo:'.($parts[2] ?? '') : $parts[0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        return collect($rows)->countBy(fn ($r) => $r[$key] === LegacyImportPlan::ADMIN ? '(admin)' : (string) $r[$key])
            ->sortDesc()->all();
    }

    /* ---------------- --awaiting ---------------- */

    private function listAwaiting(): int
    {
        $rows = DB::table('leads')
            ->join('lead_import_records as r', 'r.lead_id', '=', 'leads.id')
            ->leftJoin('users as u', 'u.id', '=', 'leads.assigned_to')
            ->where('r.outcome', 'created')
            ->whereNull('leads.deleted_at')
            ->whereNotIn('leads.stage', CrmTaxonomy::terminalStages())
            ->whereNotExists(fn ($q) => $q->from('todos')->whereColumn('todos.lead_id', 'leads.id')->where('status', 'pending'))
            ->orderBy('u.first_name')->orderBy('leads.stage')
            ->get(['leads.id', 'r.source_row', 'leads.first_name', 'leads.last_name', 'leads.mobile_number', 'leads.stage', 'u.first_name as owner_first', 'u.last_name as owner_last']);

        $this->info("{$rows->count()} imported open lead(s) still have no follow-up scheduled.");
        $this->table(
            ['lead', 'sheet row', 'name', 'mobile', 'stage', 'assigned to'],
            $rows->map(fn ($r) => [$r->id, $r->source_row, trim("{$r->first_name} {$r->last_name}"), $r->mobile_number ?? '—', $r->stage, trim("{$r->owner_first} {$r->owner_last}")])->all(),
        );

        return self::SUCCESS;
    }

    /* ---------------- output helpers ---------------- */

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    /**
     * @param  array<string|int, mixed>  $pairs
     */
    private function pairs(array $pairs): void
    {
        if ($pairs === []) {
            $this->line('  (none)');

            return;
        }
        $width = max(array_map(fn ($k) => mb_strlen((string) $k), array_keys($pairs)));
        foreach ($pairs as $label => $value) {
            $this->line('  '.str_pad((string) $label, $width + 2 + strlen((string) $label) - mb_strlen((string) $label)).$value);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function listOrNone(array $items): string
    {
        return $items === [] ? '—' : implode(', ', $items);
    }

    /**
     * @param  list<string>  $problems
     */
    private function refuse(array $problems): int
    {
        $this->newLine();
        $this->error(count($problems).' problem(s) — nothing was written:');
        foreach (array_slice($problems, 0, 50) as $problem) {
            $this->line("  - {$problem}");
        }
        if (count($problems) > 50) {
            $this->line('  … and '.(count($problems) - 50).' more');
        }

        return self::FAILURE;
    }

    private function remindScheduler(): void
    {
        $this->newLine();
        $this->warn('The scheduler is still paused. Re-enable it when you have checked the import: php artisan schedule:resume');
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : base_path($path);
    }
}
