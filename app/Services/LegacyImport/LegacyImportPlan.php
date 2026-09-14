<?php

namespace App\Services\LegacyImport;

use Illuminate\Support\Str;
use JsonException;

/**
 * The whole legacy import, worked out in memory before anything is written.
 *
 * Reads the restructured JSON in master-data/db/ and turns it into the rows
 * that will be inserted: reference data, then one GROUP per lead to create.
 * A group is the surviving source row plus every row absorbed into it —
 * the rows that share its mobile number AND its project, which the unique
 * index on leads would otherwise refuse.
 *
 * Nothing here touches the database. That is what lets `--dry-run` report
 * everything the real run will do without writing a byte; LegacyImporter
 * resolves the names to ids and does the writing.
 *
 * Ids do not exist yet, so rows name each other by key: a lead carries
 * `project_key`, `owner_name` and `partner_name_key`, and a todo `owner_name`.
 */
class LegacyImportPlan
{
    public const SOURCE_FILE = 'master_sheet';

    public const UNASSIGNED_PROJECT_KEY = '__unassigned__';

    public const UNASSIGNED_PROJECT_NAME = 'Unassigned';

    /** Stands in for "the admin" wherever the sheet names nobody. */
    public const ADMIN = '__admin__';

    /**
     * What an old follow-up call records as its outcome stage.
     *
     * Null: the call and its remark are kept, but it is not written as a
     * stage transition. The sheet never said where a call left the lead, and
     * 6,353 of the remarks read "CNR" — recording them all as `connected`
     * would put thousands of moves that never happened into every report
     * that reads todos.outcome_stage.
     */
    public const FOLLOW_UP_OUTCOME = null;

    private const DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    private const VISIT_COLUMNS = ['first_visit', 'second_visit', 'third_visit'];

    private const FOLLOW_UP_COLUMNS = ['follow_up_1', 'follow_up_2', 'follow_up_3', 'follow_up_4', 'follow_up_5'];

    /** @var list<string> problems that stop the import */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $inputRows = 0;

    public int $inputTodos = 0;

    public int $inputPartnerPairs = 0;

    /** @var array<string, array{name: string, first_seen: string}> keyed by project key */
    public array $projects = [];

    /** @var array<string, array{first_name: string, last_name: string, email: string, first_seen: string}> keyed by the name as written */
    public array $users = [];

    /** @var array<string, array{label: string, first_seen: ?string}> keyed by source key */
    public array $sources = [];

    /** @var array<string, array{name: string, phone: string, alt_phone: ?string, first_seen: ?string, numbers: list<string>, names: list<string>}> keyed by name_key */
    public array $partners = [];

    /**
     * @var list<array{
     *     lead: array<string, mixed>,
     *     todos: list<array<string, mixed>>,
     *     records: list<array<string, mixed>>,
     *     survivor_row: int,
     * }>
     */
    public array $groups = [];

    /** @var array<string, int> */
    public array $todoCounts = [
        'visits' => 0,
        'follow_ups' => 0,
        'date_borrowed' => 0,
        'absorbed_stage' => 0,
        'final_stage' => 0,
        'stage_already_recorded_same_day' => 0,
    ];

    /** @var list<string> every stage key a lead or a todo will carry */
    public array $stagesUsed = [];

    /** @var list<string> every source key a lead will carry */
    public array $sourcesUsed = [];

    /** @var list<string> */
    public array $lostReasonsUsed = [];

    /** @var array<string, string> channel_partners.json key (cp_###) => name_key */
    private array $pairToNameKey = [];

    /**
     * @param  list<string>  $terminalStages
     */
    private function __construct(
        private readonly string $directory,
        private readonly string $today,
        private readonly array $terminalStages,
    ) {}

    /**
     * @param  string  $today  Y-m-d in the application's timezone
     * @param  list<string>  $terminalStages
     */
    public static function fromDirectory(string $directory, string $today, array $terminalStages): self
    {
        $plan = new self(rtrim($directory, '/\\'), $today, $terminalStages);
        $plan->assemble();

        return $plan;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array<string, mixed>> every record, created and absorbed */
    public function records(): array
    {
        return array_merge(...array_column($this->groups, 'records') ?: [[]]);
    }

    /* ====================================================================
     | Assembly
     ==================================================================== */

    private function assemble(): void
    {
        $leads = $this->read('leads.json');
        $todos = $this->read('todos.json');
        $projects = $this->read('projects.json');
        $users = $this->read('users.json');
        $sources = $this->read('sources.json');
        $partners = $this->read('channel_partners.json');

        if ($this->errors !== []) {
            return;
        }

        $this->inputRows = count($leads);
        $this->inputTodos = count($todos);
        $this->inputPartnerPairs = count($partners);

        $byRow = [];
        foreach ($leads as $lead) {
            $row = $lead['_row_number'] ?? null;
            if (! is_int($row) || isset($byRow[$row])) {
                $this->errors[] = 'leads.json: missing or repeated _row_number '.json_encode($row);

                continue;
            }
            $byRow[$row] = $lead;
        }

        $this->projectsFrom($projects, $byRow);
        $this->usersFrom($users, $byRow);
        $this->sourcesFrom($sources, $byRow);
        $this->partnersFrom($partners, $byRow);

        $todosByRow = [];
        foreach ($todos as $todo) {
            $row = $todo['lead_row_number'] ?? null;
            if (! isset($byRow[$row])) {
                $this->errors[] = 'todos.json: a todo names lead row '.json_encode($row).', which is not in leads.json';

                continue;
            }
            $todosByRow[$row][] = $todo;
        }

        foreach ($byRow as $row => $lead) {
            $this->validateLead($row, $lead);
        }

        if ($this->errors !== []) {
            return;
        }

        foreach ($this->groupRows($byRow) as $members) {
            $this->groups[] = $this->buildGroup($members, $todosByRow);
        }

        $accounted = count($this->records());
        if ($accounted !== $this->inputRows) {
            $this->errors[] = "Accounting: {$this->inputRows} rows in, {$accounted} recorded out.";
        }

        $this->stagesUsed = array_values(array_unique(array_merge(
            array_column(array_column($this->groups, 'lead'), 'stage'),
            array_filter(array_column(array_merge(...array_column($this->groups, 'todos')), 'outcome_stage')),
        )));
        $this->sourcesUsed = array_values(array_unique(array_column(array_column($this->groups, 'lead'), 'source')));
        $this->lostReasonsUsed = array_values(array_unique(array_filter(
            array_column(array_column($this->groups, 'lead'), 'reason'),
        )));
    }

    /** @return list<array<string, mixed>> */
    private function read(string $file): array
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            $this->errors[] = "Missing input file: {$path}";

            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->errors[] = "{$file} is not valid JSON: {$e->getMessage()}";

            return [];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            $this->errors[] = "{$file} must hold a JSON list.";

            return [];
        }

        return $data;
    }

    /* ---------------- reference data ---------------- */

    /**
     * @param  list<array<string, mixed>>  $projects
     * @param  array<int, array<string, mixed>>  $byRow
     */
    private function projectsFrom(array $projects, array $byRow): void
    {
        foreach ($projects as $project) {
            $this->projects[$project['key']] = ['name' => $project['name'], 'first_seen' => null];
        }

        foreach ($byRow as $lead) {
            $key = $lead['project_key'] ?? self::UNASSIGNED_PROJECT_KEY;

            if ($key === self::UNASSIGNED_PROJECT_KEY) {
                $this->projects[$key] ??= ['name' => self::UNASSIGNED_PROJECT_NAME, 'first_seen' => null];
            }

            if (! isset($this->projects[$key])) {
                $this->errors[] = "Row {$lead['_row_number']}: project_key '{$key}' is not in projects.json";

                continue;
            }

            $this->projects[$key]['first_seen'] = $this->earliest($this->projects[$key]['first_seen'], $lead['created_at']);
        }

        // a project nobody's lead points at is not created
        $this->projects = array_filter($this->projects, fn (array $p) => $p['first_seen'] !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @param  array<int, array<string, mixed>>  $byRow
     */
    private function usersFrom(array $users, array $byRow): void
    {
        foreach ($users as $user) {
            $this->users[$user['name']] = [
                'first_name' => $user['proposed_first_name'],
                'last_name' => $user['proposed_last_name'],
                'email' => 'legacy+'.Str::slug($user['name'], '.').'@import.invalid',
                'first_seen' => null,
            ];
        }

        foreach ($byRow as $lead) {
            $name = $lead['assigned_to_name'];
            if ($name === null) {
                continue;
            }
            if (! isset($this->users[$name])) {
                $this->errors[] = "Row {$lead['_row_number']}: assigned user '{$name}' is not in users.json";

                continue;
            }
            $this->users[$name]['first_seen'] = $this->earliest($this->users[$name]['first_seen'], $lead['created_at']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<int, array<string, mixed>>  $byRow
     */
    private function sourcesFrom(array $sources, array $byRow): void
    {
        foreach ($sources as $source) {
            $this->sources[$source['proposed_key']] ??= [
                'label' => $source['source_value'] !== '' ? $source['source_value'] : Str::headline($source['proposed_key']),
                'first_seen' => null,
            ];
        }

        foreach ($byRow as $lead) {
            // an unknown key is not an error yet: it may already be a live
            // source. LegacyImporter::preflight() is what fails it loudly.
            if (isset($this->sources[$lead['source']])) {
                $this->sources[$lead['source']]['first_seen'] = $this->earliest(
                    $this->sources[$lead['source']]['first_seen'],
                    $lead['created_at'],
                );
            }
        }
    }

    /**
     * One partner per name_key, because channel_partners is unique on
     * (name_key, type): "Shailesh Thakkar" and "Shailesh thakkar" are one row.
     * The first spelling seen names it, and its first two distinct numbers
     * become phone and alt_phone. Every lead still keeps its broker name and
     * number exactly as written, in broker_name and in its import record.
     *
     * @param  list<array<string, mixed>>  $partners
     * @param  array<int, array<string, mixed>>  $byRow
     */
    private function partnersFrom(array $partners, array $byRow): void
    {
        foreach ($partners as $pair) {
            $key = $pair['name_key'];
            if ($key === '') {
                $this->errors[] = "channel_partners.json: '{$pair['name']}' reduces to an empty name_key";

                continue;
            }

            $this->partners[$key] ??= ['name' => $pair['name'], 'numbers' => [], 'names' => [], 'first_seen' => null];
            $this->partners[$key]['names'][] = $pair['name'];
            foreach ([$pair['phone'], $pair['alt_phone']] as $number) {
                if ($number !== null && $number !== '' && ! in_array($number, $this->partners[$key]['numbers'], true)) {
                    $this->partners[$key]['numbers'][] = $number;
                }
            }
            $this->pairToNameKey[$pair['key']] = $key;
        }

        foreach ($this->partners as $key => $partner) {
            $this->partners[$key]['phone'] = $partner['numbers'][0] ?? '';
            $this->partners[$key]['alt_phone'] = $partner['numbers'][1] ?? null;
        }

        foreach ($byRow as $row => $lead) {
            $pair = $lead['channel_partner_key'];
            if ($pair === null) {
                continue;
            }
            if (! isset($this->pairToNameKey[$pair])) {
                $this->errors[] = "Row {$row}: channel_partner_key '{$pair}' is not in channel_partners.json";

                continue;
            }
            $key = $this->pairToNameKey[$pair];
            $this->partners[$key]['first_seen'] = $this->earliest($this->partners[$key]['first_seen'], $lead['created_at']);
        }
    }

    /* ---------------- leads ---------------- */

    /**
     * @param  array<string, mixed>  $lead
     */
    private function validateLead(int $row, array $lead): void
    {
        foreach (['created_at', 'updated_at', 'last_activity_at', 'stage_changed_at'] as $field) {
            $value = $lead[$field] ?? null;
            if ($value === null) {
                if ($field === 'created_at' || $field === 'updated_at') {
                    $this->errors[] = "Row {$row}: {$field} is empty";
                }

                continue;
            }
            $this->checkDate("Row {$row} {$field}", $value);
        }

        if (($lead['stage'] ?? null) === null || $lead['stage'] === '') {
            $this->errors[] = "Row {$row}: no stage";
        }
        if (($lead['source'] ?? null) === null || $lead['source'] === '') {
            $this->errors[] = "Row {$row}: no source";
        }
    }

    private function checkDate(string $what, string $value): void
    {
        if (! preg_match(self::DATETIME, $value)) {
            $this->errors[] = "{$what}: '{$value}' is not Y-m-d H:i:s";
        } elseif (substr($value, 0, 10) === $this->today) {
            $this->errors[] = "{$what}: '{$value}' is today's date";
        }
    }

    /**
     * Same mobile and same project is one lead; everything else stands alone.
     * A lead with no mobile can never be the same as another one.
     *
     * Newest first inside a group — the order `_duplicate_rank` already
     * gives — so the survivor is always index 0.
     *
     * @param  array<int, array<string, mixed>>  $byRow
     * @return list<list<array<string, mixed>>>
     */
    private function groupRows(array $byRow): array
    {
        $groups = [];
        foreach ($byRow as $row => $lead) {
            $key = $lead['mobile_number'] === null
                ? "row:{$row}"
                : $lead['mobile_number'].'|'.($lead['project_key'] ?? self::UNASSIGNED_PROJECT_KEY);
            $groups[$key][] = $lead;
        }

        foreach ($groups as &$members) {
            usort($members, fn (array $a, array $b) => [$a['_duplicate_rank'] ?? 0, $b['created_at'], $b['_row_number']]
                <=> [$b['_duplicate_rank'] ?? 0, $a['created_at'], $a['_row_number']]);
        }

        return array_values($groups);
    }

    /**
     * @param  list<array<string, mixed>>  $members  survivor first
     * @param  array<int, list<array<string, mixed>>>  $todosByRow
     * @return array{lead: array<string, mixed>, todos: list<array<string, mixed>>, records: list<array<string, mixed>>, survivor_row: int}
     */
    private function buildGroup(array $members, array $todosByRow): array
    {
        $survivor = $members[0];
        $absorbed = array_slice($members, 1);
        $flags = array_fill_keys(array_column($members, '_row_number'), []);

        $todos = [];
        foreach ($members as $member) {
            foreach ($this->memberTodos($member, $todosByRow[$member['_row_number']] ?? [], $flags) as $todo) {
                $todos[] = $todo;
            }
        }

        foreach ($absorbed as $row) {
            $todos[] = $this->absorbedStageTodo($row);
            $this->todoCounts['absorbed_stage']++;
        }

        [$todos, $stageChangedAt] = $this->settleHistory($survivor, $members, $todos, $flags);

        $createdAt = min(array_column($members, 'created_at'));
        $lastActivity = max(array_filter(array_column($members, 'last_activity_at')) ?: [null]);
        $updatedAt = max(array_merge(array_column($members, 'updated_at'), [$stageChangedAt ?? $createdAt]));

        $partnerKey = $survivor['channel_partner_key'] !== null && $survivor['source'] === 'broker'
            ? $this->pairToNameKey[$survivor['channel_partner_key']]
            : null;
        if ($survivor['channel_partner_key'] !== null && $partnerKey === null) {
            $flags[$survivor['_row_number']][] = 'channel_partner_not_linked:source_is_not_broker';
        }

        $open = ! in_array($survivor['stage'], $this->terminalStages, true);

        $lead = [
            'first_name' => $survivor['first_name'],
            'middle_name' => $survivor['middle_name'],
            'last_name' => $survivor['last_name'],
            'mobile_number' => $survivor['mobile_number'],
            'email' => $survivor['email'],
            'project_key' => $survivor['project_key'] ?? self::UNASSIGNED_PROJECT_KEY,
            'source' => $survivor['source'],
            'external_id' => $survivor['external_id'],
            'broker_name' => $survivor['broker_name'],
            'partner_name_key' => $partnerKey,
            'stage' => $survivor['stage'],
            'stage_changed_at' => $stageChangedAt,
            'not_connected_count' => $survivor['not_connected_count'],
            'owner_name' => $survivor['assigned_to_name'] ?? self::ADMIN,
            'created_by' => null,
            'requirement' => $survivor['requirement'],
            'reason' => $survivor['reason'],
            'booked_unit' => $survivor['booked_unit'],
            'booking_date' => $survivor['booking_date'],
            'last_activity_at' => $lastActivity,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'deleted_at' => null,
        ];

        foreach (['created_at', 'updated_at', 'stage_changed_at', 'last_activity_at'] as $field) {
            if ($lead[$field] !== null) {
                $this->checkDate("Lead from row {$survivor['_row_number']} {$field}", $lead[$field]);
            }
        }

        if ($survivor['assigned_to_name'] === null) {
            $flags[$survivor['_row_number']][] = 'assigned_to_admin:no_assigned_user';
        }
        if ($survivor['project_key'] === null) {
            $flags[$survivor['_row_number']][] = 'project_unassigned:no_project';
        }
        if ($survivor['mobile_number'] === null) {
            $flags[$survivor['_row_number']][] = 'mobile_stored_as_null';
        }
        if ($open) {
            $flags[$survivor['_row_number']][] = 'awaiting_follow_up';
        }
        if ($absorbed !== []) {
            $flags[$survivor['_row_number']][] = 'survivor_of_rows:'.implode(',', array_column($absorbed, '_row_number'));
        }

        $records = [];
        foreach ($members as $index => $member) {
            $row = $member['_row_number'];
            if ($index > 0) {
                $flags[$row][] = "absorbed_into_row:{$survivor['_row_number']}";
            }
            $records[] = [
                'source_file' => self::SOURCE_FILE,
                'source_row' => $row,
                'outcome' => $index === 0 ? 'created' : 'absorbed',
                'duplicate_group' => $member['_duplicate_group'],
                'duplicate_rank' => $member['_duplicate_rank'],
                'awaiting_follow_up' => $index === 0 && $open,
                'imported_todo_count' => $index === 0 ? count($todos) : 0,
                'filled' => $member['_filled'],
                'flags' => array_values(array_merge($member['_flags'], $flags[$row])),
                'legacy' => $member,
            ];
        }

        return ['lead' => $lead, 'todos' => $todos, 'records' => $records, 'survivor_row' => $survivor['_row_number']];
    }

    /**
     * A member's own visits and follow-ups, with a date for every one.
     *
     * todos.scheduled_at is NOT NULL, so a remark whose date cell was empty
     * (or held text) borrows the nearest earlier date in the same series on
     * the same row, or the row's created_at when there is none. Flagged.
     *
     * @param  array<string, mixed>  $member
     * @param  list<array<string, mixed>>  $todos
     * @param  array<int, list<string>>  $flags
     * @return list<array<string, mixed>>
     */
    private function memberTodos(array $member, array $todos, array &$flags): array
    {
        $row = $member['_row_number'];
        $dated = [];
        foreach ($todos as $todo) {
            if ($todo['scheduled_at'] !== null) {
                $dated[$todo['_source_date_column']] = $todo['scheduled_at'];
            }
        }

        $out = [];
        foreach ($todos as $todo) {
            $column = $todo['_source_date_column'];
            $isVisit = in_array($column, self::VISIT_COLUMNS, true);
            $date = $todo['scheduled_at'];

            if ($date === null) {
                [$date, $from] = $this->borrowDate($column, $isVisit ? self::VISIT_COLUMNS : self::FOLLOW_UP_COLUMNS, $dated, $member);
                $flags[$row][] = "todo:{$column}:date_borrowed_from:{$from}";
                $this->todoCounts['date_borrowed']++;
            } elseif (substr($date, 0, 10) > $this->today) {
                $flags[$row][] = "todo:{$column}:dated_in_the_future";
            }

            $this->checkDate("Todo row {$row} {$column}", $date);
            $this->todoCounts[$isVisit ? 'visits' : 'follow_ups']++;

            $out[] = [
                'source_row' => $row,
                'column' => $column,
                'owner_name' => $todo['assigned_to_name'] ?? self::ADMIN,
                'date' => $date,
                'type' => $todo['type'],
                'remarks' => $todo['remarks'],
                'outcome_stage' => $isVisit ? $todo['outcome_stage'] : self::FOLLOW_UP_OUTCOME,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $series
     * @param  array<string, string>  $dated
     * @param  array<string, mixed>  $member
     * @return array{0: string, 1: string}
     */
    private function borrowDate(string $column, array $series, array $dated, array $member): array
    {
        for ($i = array_search($column, $series, true) - 1; $i >= 0; $i--) {
            if (isset($dated[$series[$i]])) {
                return [$dated[$series[$i]], $series[$i]];
            }
        }

        return [$member['created_at'], 'created_at'];
    }

    /**
     * An absorbed row's stage, kept as history on the surviving lead.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function absorbedStageTodo(array $row): array
    {
        $legacy = $row['_legacy'];

        return [
            'source_row' => $row['_row_number'],
            'column' => 'absorbed_stage',
            'owner_name' => $row['assigned_to_name'] ?? self::ADMIN,
            'date' => $row['created_at'],
            'type' => 'call',
            'remarks' => sprintf(
                'From a duplicate record: Master Sheet row %d, created %s, stage "%s".',
                $row['_row_number'],
                substr($row['created_at'], 0, 10),
                $legacy['secondary_stages'],
            ),
            // `fresh` is not a transition — see LeadFollowUpService::onLeadCreated()
            'outcome_stage' => $row['stage'] === 'fresh' ? null : $row['stage'],
        ];
    }

    /**
     * Put the group's history in order and make its newest row agree with the
     * lead's stage, which is what crm:check-consistency and every report
     * reading todos.outcome_stage expect.
     *
     *   - Oldest first; on the same day the row matching the lead's stage goes
     *     last, so it is the one read as "latest".
     *   - The same stage twice on the same day is one transition written twice
     *     (a fault to check-consistency): the second keeps its remark and
     *     loses its outcome_stage. Flagged.
     *   - If the newest row already records the lead's stage and is the
     *     survivor's own, that row's date is stage_changed_at. Otherwise one
     *     more row records the stage at the lead's last known date — as the
     *     app does for a lead added at a later stage — and that date is
     *     stage_changed_at.
     *   - `fresh` gets no row and a null stage_changed_at.
     *
     * @param  array<string, mixed>  $survivor
     * @param  list<array<string, mixed>>  $members
     * @param  list<array<string, mixed>>  $todos
     * @param  array<int, list<string>>  $flags
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function settleHistory(array $survivor, array $members, array $todos, array &$flags): array
    {
        $stage = $survivor['stage'];

        foreach ($todos as $i => &$todo) {
            $todo['sequence'] = $i;
        }
        unset($todo);

        usort($todos, fn (array $a, array $b) => [$a['date'], $a['outcome_stage'] === $stage, $a['sequence']]
            <=> [$b['date'], $b['outcome_stage'] === $stage, $b['sequence']]);

        $seen = [];
        foreach ($todos as &$todo) {
            if ($todo['outcome_stage'] === null) {
                continue;
            }
            $key = $todo['outcome_stage'].'|'.$todo['date'];
            if (isset($seen[$key])) {
                $flags[$todo['source_row']][] = "todo:{$todo['column']}:stage_already_recorded_same_day";
                $todo['outcome_stage'] = null;
                $this->todoCounts['stage_already_recorded_same_day']++;
            }
            $seen[$key] = true;
        }
        unset($todo);

        if ($stage === 'fresh') {
            return [$this->withoutSequence($todos), null];
        }

        $history = array_values(array_filter($todos, fn (array $t) => $t['outcome_stage'] !== null));
        $newest = end($history) ?: null;

        // only the survivor's own history can stand for its stage: an absorbed
        // row reaching the same stage is that older record's past, not when
        // the lead got where it is now
        if ($newest !== null && $newest['outcome_stage'] === $stage && $newest['source_row'] === $survivor['_row_number']) {
            return [$this->withoutSequence($todos), $newest['date']];
        }

        $date = max(array_merge(array_column($members, 'updated_at'), $newest ? [$newest['date']] : []));

        // the row below becomes the one transition to this stage on that day
        foreach ($todos as &$todo) {
            if ($todo['outcome_stage'] === $stage && $todo['date'] === $date) {
                $flags[$todo['source_row']][] = "todo:{$todo['column']}:stage_already_recorded_same_day";
                $todo['outcome_stage'] = null;
                $this->todoCounts['stage_already_recorded_same_day']++;
            }
        }
        unset($todo);

        $todos[] = [
            'source_row' => $survivor['_row_number'],
            'column' => 'final_stage',
            'owner_name' => $survivor['assigned_to_name'] ?? self::ADMIN,
            'date' => $date,
            'type' => 'call',
            'remarks' => sprintf(
                'Imported from Master Sheet row %d at this stage: "%s".',
                $survivor['_row_number'],
                $survivor['_legacy']['secondary_stages'],
            ),
            'outcome_stage' => $stage,
        ];
        $this->todoCounts['final_stage']++;

        return [$this->withoutSequence($todos), $date];
    }

    /**
     * @param  list<array<string, mixed>>  $todos
     * @return list<array<string, mixed>>
     */
    private function withoutSequence(array $todos): array
    {
        return array_map(function (array $todo) {
            unset($todo['sequence']);

            return $todo;
        }, $todos);
    }

    private function earliest(?string $current, string $candidate): string
    {
        return $current === null || $candidate < $current ? $candidate : $current;
    }
}
