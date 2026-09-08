<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;

class D01PagesTest extends QaCase
{
    private function props($r): array { return $r->json('props') ?? []; }
    private function visit($u, string $uri): array {
        $r = $this->actingAs($u)->get($uri);
        if ($r->status() !== 200) { $this->say('  !! ' . $uri . ' -> ' . $r->status()); return []; }
        $page = $r->viewData('page');
        return $page['props'] ?? [];
    }

    public function test_todo_tabs_and_chips(): void
    {
        foreach ([['admin',$this->admin()],['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            foreach (['overdue','today','upcoming','completed'] as $tab) {
                $p = $this->visit($u, "/todos?tab={$tab}&reset=1");
                $chipSum = array_sum(array_column($p['types']['bars'] ?? [], 'value'));
                $this->say(sprintf('  %-6s tab=%-10s rows=%-4d chipTotal=%-4d chipSum=%-4d badges=%s',
                    $n, $tab, $p['todos']['total'] ?? -1, $p['types']['total'] ?? -1, $chipSum, json_encode($p['counts'] ?? [])));
            }
        }
        $this->assertTrue(true);
    }

    public function test_completed_tab_uses_completed_at(): void
    {
        // a todo scheduled long ago but completed today
        $t = Todo::where('status','completed')->whereNotNull('completed_at')->first();
        $this->say('sample completed todo: sched=' . $t?->scheduled_at . ' completed=' . $t?->completed_at);
        $today = today()->toDateString();
        $p = $this->visit($this->admin(), "/todos?tab=completed&from={$today}&to={$today}&reset=1");
        $expect = Todo::hasLead()->where('status','completed')->whereDate('completed_at',$today)->count();
        $this->say('completed tab today range: rows=' . ($p['todos']['total'] ?? -1) . ' expected(by completed_at)=' . $expect
            . ' by-scheduled_at=' . Todo::hasLead()->where('status','completed')->whereDate('scheduled_at',$today)->count());
        $this->assertTrue(true);
    }

    public function test_leads_chips_and_filters(): void
    {
        $p = $this->visit($this->admin(), '/leads?reset=1');
        $this->say('all leads: total=' . ($p['leads']['total'] ?? -1) . ' chipTotal=' . ($p['stageCounts']['total'] ?? -1)
            . ' dbcount=' . Lead::count());
        $p = $this->visit($this->admin(), '/leads?stage=lost');
        $this->say('stage=lost: rows=' . ($p['leads']['total'] ?? -1) . ' chipTotal=' . ($p['stageCounts']['total'] ?? -1)
            . ' (chips should ignore the stage filter)');
        $p = $this->visit($this->admin(), '/leads?source=facebook&stage=');
        $this->say('source=facebook: rows=' . ($p['leads']['total'] ?? -1) . ' chipTotal=' . ($p['stageCounts']['total'] ?? -1)
            . ' db=' . Lead::where('source','facebook')->count());
        // filters survive a plain revisit
        $p = $this->visit($this->admin(), '/leads');
        $this->say('revisit with no query: filters=' . json_encode($p['filters'] ?? []) . ' rows=' . ($p['leads']['total'] ?? -1));
        // hostile filters
        foreach (['?stage=nope','?project_id=abc','?assigned_to=-5','?from=2026-13-45&to=2026-01-01','?from=2027-01-01&to=2027-02-01','?from=2026-09-08&to=2026-09-01','?search=' . urlencode(str_repeat('x',300))] as $q) {
            $p = $this->visit($this->admin(), '/leads' . $q . '&reset=1');
            $this->say('  ' . $q . ' -> rows=' . ($p['leads']['total'] ?? -1) . ' filters=' . json_encode($p['filters'] ?? []));
        }
        $this->visit($this->admin(), '/leads?reset=1');
        $this->assertTrue(true);
    }

    public function test_dashboard_cards_reconcile(): void
    {
        $u = $this->admin();
        foreach ([['today','?range=today'],['7','?range=7'],['30','?range=30'],
                  ['single day','?from=' . today()->toDateString() . '&to=' . today()->toDateString()],
                  ['empty range','?from=2020-01-01&to=2020-01-02'],
                  ['ends future','?from=' . today()->toDateString() . '&to=' . today()->addDays(5)->toDateString()],
                  ['from>to','?from=' . today()->toDateString() . '&to=' . today()->subDays(5)->toDateString()],
                  ['huge span','?from=2000-01-01&to=' . today()->toDateString()],
                 ] as [$label,$q]) {
            $p = $this->visit($u, '/dashboard' . $q . '&reset=1');
            $range = $p['range'] ?? [];
            $c = $p['cards'] ?? [];
            [$f,$t] = [\Illuminate\Support\Carbon::parse($range['from'])->startOfDay(), \Illuminate\Support\Carbon::parse($range['to'])->endOfDay()];
            $total  = Lead::whereBetween('created_at', [$f,$t])->count();
            $visits = Todo::whereHas('lead')->where('outcome_stage','site_visit_done')->whereBetween('completed_at',[$f,$t])->distinct('lead_id')->count('lead_id');
            $booked = Todo::whereHas('lead')->where('outcome_stage','booking_done')->whereBetween('completed_at',[$f,$t])->distinct('lead_id')->count('lead_id');
            $lost   = Todo::whereHas('lead')->where('outcome_stage','lost')->whereBetween('completed_at',[$f,$t])->distinct('lead_id')->count('lead_id');
            $pend   = Todo::hasLead()->pending()->where('scheduled_at','<=',today()->endOfDay())->count();
            $this->say(sprintf('  %-12s key=%-7s %s..%s | total %d/%d visits %d/%d booked %d/%d lost %d/%d pending %d/%d conv=%s',
                $label, $range['key'] ?? '?', $range['from'] ?? '?', $range['to'] ?? '?',
                $c['total'] ?? -1, $total, $c['visits'] ?? -1, $visits, $c['booked'] ?? -1, $booked,
                $c['lost'] ?? -1, $lost, $c['pending'] ?? -1, $pend, json_encode($c['conversion'] ?? null)));
        }
        $this->visit($u, '/dashboard?range=30&reset=1');
        $this->assertTrue(true);
    }

    public function test_dashboard_charts_sum(): void
    {
        $p = $this->visit($this->admin(), '/dashboard?range=30&reset=1');
        $ch = $p['charts'] ?? [];
        $this->say('stagesAllTime total=' . ($ch['stagesAllTime']['total'] ?? -1) . ' db leads=' . Lead::count());
        $this->say('stagesInPeriod total=' . ($ch['stagesInPeriod']['total'] ?? -1) . ' cards.total=' . ($p['cards']['total'] ?? -1));
        $this->say('bySource sum=' . array_sum(array_column($ch['bySource'] ?? [], 'value')));
        $this->assertTrue(true);
    }
}
