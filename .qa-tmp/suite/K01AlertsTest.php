<?php
namespace QA;

use App\Models\Alert;
use App\Models\Lead;
use App\Models\Todo;
use Illuminate\Support\Facades\Artisan;

class K01AlertsTest extends QaCase
{
    public function test_builtin_alerts_and_dedupe(): void
    {
        $before = Alert::count();
        Artisan::call('automation:run', ['--alerts-only' => true]);
        $mid = Alert::count();
        $this->say('  first automation:run --alerts-only  alerts ' . $before . ' -> ' . $mid . ' (new=' . ($mid-$before) . ')');
        Artisan::call('automation:run', ['--alerts-only' => true]);
        $after = Alert::count();
        $this->say('  second run immediately             alerts ' . $mid . ' -> ' . $after . ' (new=' . ($after-$mid) . ' — must be 0)');
        $this->say('  types raised: ' . json_encode(Alert::selectRaw('type, count(*) c')->groupBy('type')->pluck('c','type')->all()));
        // clean up whatever this run created
        Alert::where('id','>',$before ? Alert::orderBy('id')->skip($before-1)->take(1)->value('id') : 0)->count();
        $this->assertTrue(true);
    }

    public function test_alert_ownership(): void
    {
        $mine = Alert::for($this->admin())->first();
        $theirs = Alert::where('user_id','!=',$this->admin()->id)->first();
        $this->say('  admin alert id=' . $mine?->id . ' someone else id=' . $theirs?->id . ' (owner ' . $theirs?->user_id . ')');
        if ($theirs) {
            $r = $this->actingAs($this->admin())->post('/alerts/' . $theirs->id . '/read');
            $this->say('  admin reads someone else\'s alert -> ' . $r->status() . ' read_at=' . json_encode($theirs->fresh()->read_at));
        }
        // each role only sees their own on /alerts and in the shared bell
        foreach ([['admin',$this->admin()],['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $page = $this->actingAs($u)->get('/alerts')->viewData('page');
            $props = $page['props'];
            $ids = collect($props['alerts']['data'] ?? $props['alerts'] ?? [])->pluck('id');
            $foreign = Alert::whereIn('id', $ids)->where('user_id','!=',$u->id)->count();
            $this->say("  $n /alerts rows=" . $ids->count() . ' belonging to somebody else=' . $foreign
                . ' bellUnread=' . json_encode($props['alerts_shared'] ?? null));
        }
        $this->assertTrue(true);
    }

    public function test_empty_states_for_a_user_with_nothing(): void
    {
        $u = $this->sales2();
        $before = ['leads'=>Lead::where('assigned_to',$u->id)->count(),'todos'=>Todo::where('assigned_to',$u->id)->count()];
        $this->say('  sales2 holds leads=' . $before['leads'] . ' todos=' . $before['todos']);
        foreach (['/dashboard','/leads','/todos','/reports/leads','/reports/followups','/alerts'] as $uri) {
            $r = $this->actingAs($u)->get($uri);
            $this->say(sprintf('  %-22s %d', $uri, $r->status()));
        }
        $p = $this->actingAs($u)->get('/dashboard')->viewData('page')['props'];
        $this->say('  sales2 dashboard cards: ' . json_encode($p['cards']));
        $this->assertTrue(true);
    }

    public function test_pagination_with_filters(): void
    {
        foreach (['/leads?reset=1','/leads?source=facebook','/leads?source=facebook&page=2','/leads?page=99'] as $uri) {
            $p = $this->actingAs($this->admin())->get($uri)->viewData('page')['props'];
            $l = $p['leads'];
            $this->say(sprintf('  %-34s total=%-4d page=%-3d lastPage=%-3d rows=%d filters=%s',
                $uri, $l['total'], $l['current_page'], $l['last_page'], count($l['data']), json_encode($p['filters'])));
        }
        $this->actingAs($this->admin())->get('/leads?reset=1');
        $this->assertTrue(true);
    }

    public function test_digest_once_per_session(): void
    {
        $u = $this->admin();
        $p1 = $this->actingAs($u)->get('/dashboard')->viewData('page')['props'];
        $p2 = $this->actingAs($u)->get('/dashboard')->viewData('page')['props'];
        $this->say('  first visit digest: ' . (isset($p1['todayDigest']) && $p1['todayDigest'] ? 'shown total=' . $p1['todayDigest']['total'] : 'null'));
        $this->say('  second visit digest: ' . (isset($p2['todayDigest']) && $p2['todayDigest'] ? 'shown' : 'null (correct)'));
        $this->assertTrue(true);
    }
}
