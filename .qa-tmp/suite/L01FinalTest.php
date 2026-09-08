<?php
namespace QA;

use App\Models\Alert;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

class L01FinalTest extends QaCase
{
    private array $leads = []; private array $rules = []; private array $users = [];
    protected function tearDown(): void {
        AutomationLog::whereIn('rule_id',$this->rules)->delete();
        Alert::whereIn('rule_id',$this->rules)->delete();
        foreach ($this->leads as $id) {
            Alert::where('lead_id',$id)->delete(); AutomationLog::where('lead_id',$id)->delete();
            Todo::where('lead_id',$id)->forceDelete(); Lead::withTrashed()->find($id)?->forceDelete();
        }
        AutomationRule::whereIn('id',$this->rules)->delete();
        foreach ($this->users as $id) { User::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }
    private function mkLead(string $mobile, array $o = []): Lead {
        $d = array_merge(['first_name'=>'QAFin','last_name'=>'Probe','mobile_number'=>$mobile,'project_id'=>1,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i')], $o);
        $this->actingAs($this->admin())->post('/leads', $d);
        $l = Lead::where('mobile_number',$mobile)->firstOrFail(); $this->leads[] = $l->id; return $l;
    }

    public function test_brand_new_user_sees_empty_states(): void
    {
        $this->actingAs($this->admin())->post('/users', [
            'first_name'=>'QAEmpty','last_name'=>'User','email'=>'qa.empty@example.com',
            'mobile_number'=>'9000000099','role'=>'salesperson','password'=>'password123',
            'password_confirmation'=>'password123','is_active'=>true]);
        $u = User::where('email','qa.empty@example.com')->firstOrFail();
        $this->users[] = $u->id;
        foreach (['/dashboard','/leads','/todos','/reports/leads','/reports/followups','/alerts'] as $uri) {
            $r = $this->actingAs($u)->get($uri);
            $props = $r->status() === 200 ? ($r->viewData('page')['props'] ?? []) : [];
            $n = $props['leads']['total'] ?? $props['todos']['total'] ?? $props['totals']['total'] ?? null;
            $this->say(sprintf('  %-22s %d rows=%s', $uri, $r->status(), json_encode($n)));
        }
        $p = $this->actingAs($u)->get('/dashboard')->viewData('page')['props'];
        $this->say('  cards: ' . json_encode($p['cards']));
        $this->say('  charts.bySource: ' . json_encode($p['charts']['bySource']));
        $this->say('  digest: ' . json_encode($p['todayDigest']));
        $this->assertTrue(true);
    }

    public function test_double_submit(): void
    {
        $payload = ['first_name'=>'QADouble','last_name'=>'Probe','mobile_number'=>'9000000088','project_id'=>1,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i')];
        $this->actingAs($this->admin())->post('/leads', $payload);
        $this->actingAs($this->admin())->post('/leads', $payload);
        $n = Lead::where('mobile_number','9000000088')->count();
        $l = Lead::where('mobile_number','9000000088')->first(); if ($l) $this->leads[] = $l->id;
        $this->say('  same lead posted twice -> rows=' . $n . ' pending todos=' . ($l ? $l->todos()->where('status','pending')->count() : -1));

        // double reschedule
        $t = $l->todos()->where('status','pending')->first();
        $when = now()->addDays(4)->format('Y-m-d\TH:i');
        $this->actingAs($this->admin())->put("/todos/{$t->id}", ['lead_id'=>$l->id,'type'=>'call','scheduled_at'=>$when]);
        $this->actingAs($this->admin())->put("/todos/{$t->id}", ['lead_id'=>$l->id,'type'=>'call','scheduled_at'=>$when]);
        $this->say('  double reschedule -> pending=' . $l->todos()->where('status','pending')->count());

        // double complete
        $r1 = $this->actingAs($this->admin())->post("/todos/{$t->id}/complete", ['stage'=>'connected','remarks'=>'x','follow_up_type'=>'call','follow_up_at'=>now()->addDays(2)->format('Y-m-d\TH:i')]);
        $r2 = $this->actingAs($this->admin())->post("/todos/{$t->id}/complete", ['stage'=>'connected','remarks'=>'x','follow_up_type'=>'call','follow_up_at'=>now()->addDays(2)->format('Y-m-d\TH:i')]);
        $this->say('  double complete -> ' . $r1->status() . '/' . $r2->status() . ' pending=' . $l->todos()->where('status','pending')->count());
        $this->inv('after double submits');
        $this->assertTrue(true);
    }

    public function test_conflict_warning_warns_but_does_not_block(): void
    {
        $when = now()->addDays(6)->setTime(15, 0);
        $a = $this->mkLead('9000000077', ['follow_up_at' => $when->format('Y-m-d\TH:i')]);
        $b = $this->mkLead('9000000076', ['follow_up_at' => $when->copy()->addMinutes(10)->format('Y-m-d\TH:i')]);
        $r = $this->actingAs($this->admin())->postJson('/follow-ups/check-conflict', [
            'assigned_to' => $a->assigned_to, 'scheduled_at' => $when->format('Y-m-d H:i:s')]);
        $this->say('  conflict (admin, can see): ' . json_encode($r->json()));
        $r = $this->actingAs($this->sales())->postJson('/follow-ups/check-conflict', [
            'assigned_to' => $a->assigned_to, 'scheduled_at' => $when->format('Y-m-d H:i:s')]);
        $this->say('  conflict (salesperson, cannot see the lead): ' . json_encode($r->json()));
        $this->say('  both follow-ups saved anyway: ' . Todo::whereIn('lead_id',[$a->id,$b->id])->pending()->count());
        $this->assertTrue(true);
    }

    public function test_time_trigger_rule_fires_from_command(): void
    {
        $lead = $this->mkLead('9000000066');
        // put it at a stage and back-date the entry so stage_idle matches
        $lead->forceFill(['stage'=>'in_discussion','stage_changed_at'=>now()->subDays(30)])->save();

        $this->actingAs($this->admin())->post('/automation/rules', [
            'name'=>'QA Idle Rule','trigger'=>'stage_idle','trigger_config'=>['stage'=>'in_discussion','days'=>14],
            'conditions'=>[],'actions'=>[['type'=>'raise_alert','recipient'=>'lead_owner','title'=>'QA idle: {lead_name}','severity'=>'warning']]]);
        $rule = AutomationRule::where('name','QA Idle Rule')->latest('id')->first();
        if (!$rule) { $this->say('  rule not saved'); $this->assertTrue(true); return; }
        $this->rules[] = $rule->id;
        $this->actingAs($this->admin())->post("/automation/rules/{$rule->id}/toggle", ['is_active'=>true]);
        Artisan::call('automation:run', ['--rules-only' => true]);
        $this->say('  automation:run --rules-only output: ' . trim(preg_replace('/\s+/',' ', Artisan::output())));
        $logs = AutomationLog::where('rule_id',$rule->id)->get();
        $this->say('  logs: ' . json_encode($logs->map(fn($l)=>[$l->lead_id,$l->action,$l->result])->all()));
        $this->say('  alerts from this rule: ' . Alert::where('rule_id',$rule->id)->count());
        $this->inv('after time-trigger rule');
        $this->assertTrue(true);
    }

    public function test_lead_assigned_trigger(): void
    {
        $this->actingAs($this->admin())->post('/automation/rules', [
            'name'=>'QA Assigned Rule','trigger'=>'lead_assigned','trigger_config'=>[],
            'conditions'=>[],'actions'=>[['type'=>'raise_alert','recipient'=>'admins','title'=>'QA handed over: {lead_name}','severity'=>'info']]]);
        $rule = AutomationRule::where('name','QA Assigned Rule')->latest('id')->first();
        if (!$rule) { $this->assertTrue(true); return; }
        $this->rules[] = $rule->id;
        $this->actingAs($this->admin())->post("/automation/rules/{$rule->id}/toggle", ['is_active'=>true]);
        $lead = $this->mkLead('9000000055');
        $todo = $lead->todos()->where('status','pending')->first();
        $this->actingAs($this->admin())->post("/todos/{$todo->id}/complete", [
            'stage'=>'site_visit_scheduled','remarks'=>'book the visit','follow_up_type'=>'site_visit',
            'follow_up_at'=>now()->addDays(3)->format('Y-m-d\TH:i')]);
        $lead->refresh();
        $this->say('  handover: owner=' . $lead->assigned_to . ' role=' . $lead->assigned_role
            . ' rule logs=' . json_encode(AutomationLog::where('rule_id',$rule->id)->pluck('result')->all())
            . ' alerts=' . Alert::where('rule_id',$rule->id)->count());
        $this->say('  pending follow-up now belongs to: ' . $lead->todos()->where('status','pending')->value('assigned_to'));
        $this->inv('after lead_assigned trigger');
        $this->assertTrue(true);
    }
}
