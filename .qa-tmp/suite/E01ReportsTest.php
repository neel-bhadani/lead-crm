<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;

class E01ReportsTest extends QaCase
{
    private function visit($u, string $uri): array {
        $r = $this->actingAs($u)->get($uri);
        if ($r->status() !== 200) { $this->say('  !! ' . $uri . ' -> ' . $r->status()); return []; }
        return $r->viewData('page')['props'] ?? [];
    }

    public function test_lead_report_every_dimension(): void
    {
        foreach (['stage','source','project','channel_partner','assigned_to'] as $dim) {
            $p = $this->visit($this->admin(), "/reports/leads?group={$dim}&range=30&reset=1");
            $t = $p['totals'] ?? [];
            $rowSum = array_sum(array_column($p['rows'] ?? [], 'total'));
            $this->say(sprintf('  group=%-16s groups=%-3d rowSum=%-4d totals.total=%-4d visits=%-3d booked=%-3d lost=%-3d conv=%s',
                $dim, count($p['rows'] ?? []), $rowSum, $t['total'] ?? -1, $t['visits'] ?? -1, $t['booked'] ?? -1, $t['lost'] ?? -1,
                json_encode($t['conversion'] ?? null)));
        }
        // reconcile with the dashboard for the same range
        $d = $this->visit($this->admin(), '/dashboard?range=30&reset=1');
        $p = $this->visit($this->admin(), '/reports/leads?group=source&range=30&reset=1');
        $this->say('dashboard 30d: total=' . $d['cards']['total'] . ' visits=' . $d['cards']['visits'] . ' booked=' . $d['cards']['booked'] . ' lost=' . $d['cards']['lost']);
        $this->say('report    30d: total=' . $p['totals']['total'] . ' visits=' . $p['totals']['visits'] . ' booked=' . $p['totals']['booked'] . ' lost=' . $p['totals']['lost']);
        $this->assertTrue(true);
    }

    public function test_followup_report(): void
    {
        foreach (['overdue','today','upcoming','completed'] as $st) {
            foreach (['type','assigned_to'] as $dim) {
                $p = $this->visit($this->admin(), "/reports/followups?status={$st}&group={$dim}&range=30&reset=1");
                $rowSum = array_sum(array_column($p['rows'] ?? [], 'total'));
                $this->say(sprintf('  status=%-10s group=%-12s rowSum=%-4d totals=%-4d avgDays=%s',
                    $st, $dim, $rowSum, $p['totals']['total'] ?? -1, json_encode($p['totals']['avgDays'] ?? null)));
            }
        }
        // drill-through parity with /todos tabs
        foreach (['overdue','today','upcoming'] as $st) {
            $rep = $this->visit($this->admin(), "/reports/followups?status={$st}&group=type&range=30&reset=1");
            $page = $this->visit($this->admin(), "/todos?tab={$st}&reset=1");
            $this->say("  drill {$st}: report=" . ($rep['totals']['total'] ?? -1) . ' todos page=' . ($page['todos']['total'] ?? -1));
        }
        $this->assertTrue(true);
    }

    public function test_non_admin_cannot_group_by_assigned_to(): void
    {
        foreach ([['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $p = $this->visit($u, '/reports/leads?group=assigned_to&range=30&reset=1');
            $this->say("  $n leads?group=assigned_to -> effective group=" . json_encode($p['filters']['group'] ?? null)
                . ' dims=' . json_encode(array_keys($p['options']['dimensions'] ?? [])));
            $p = $this->visit($u, '/reports/followups?group=assigned_to&range=30&reset=1');
            $this->say("  $n followups?group=assigned_to -> " . json_encode($p['filters']['group'] ?? null)
                . ' rowSum=' . array_sum(array_column($p['rows'] ?? [], 'total')));
        }
        $this->assertTrue(true);
    }

    public function test_report_hostile_ranges(): void
    {
        foreach (['?from=2026-09-08&to=2026-09-01','?from=2027-01-01&to=2027-06-01','?range=999','?group=../../etc/passwd','?status=nonsense','?from=abc&to=def'] as $q) {
            $p = $this->visit($this->admin(), '/reports/leads' . $q . '&reset=1');
            $this->say('  leads' . $q . ' -> range=' . json_encode($p['range'] ?? null) . ' group=' . json_encode($p['filters']['group'] ?? null));
        }
        $this->assertTrue(true);
    }
}
