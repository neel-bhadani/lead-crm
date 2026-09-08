<?php
namespace QA;

use App\Models\Alert;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Todo;

class I01AutomationTest extends QaCase
{
    private array $rules = []; private array $leads = []; private array $templates = [];

    protected function tearDown(): void {
        AutomationLog::whereIn('rule_id', $this->rules)->delete();
        Alert::whereIn('rule_id', $this->rules)->delete();
        MessageLog::whereIn('rule_id', $this->rules)->delete();
        foreach ($this->leads as $id) {
            MessageLog::where('lead_id',$id)->delete();
            Alert::where('lead_id',$id)->delete();
            AutomationLog::where('lead_id',$id)->delete();
            Todo::where('lead_id',$id)->forceDelete();
            Lead::withTrashed()->find($id)?->forceDelete();
        }
        AutomationRule::whereIn('id', $this->rules)->delete();
        MessageTemplate::whereIn('id', $this->templates)->delete();
        parent::tearDown();
    }

    private function mkLead(string $mobile, array $o = []): Lead {
        $d = array_merge([
            'first_name'=>'QAAuto','last_name'=>'Probe','mobile_number'=>$mobile,'project_id'=>1,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i'),
        ], $o);
        $this->actingAs($this->admin())->post('/leads', $d);
        $l = Lead::where('mobile_number',$mobile)->firstOrFail();
        $this->leads[] = $l->id;
        return $l;
    }

    private function mkRule(array $payload): ?AutomationRule {
        $r = $this->actingAs($this->admin())->post('/automation/rules', $payload);
        $rule = AutomationRule::where('name', $payload['name'])->latest('id')->first();
        if ($rule) $this->rules[] = $rule->id;
        $this->say('  create rule "' . $payload['name'] . '" -> http=' . $r->status()
            . ' saved=' . ($rule ? 'YES id='.$rule->id : 'no')
            . ' is_active=' . ($rule ? var_export($rule->is_active, true) : '-')
            . ' created_by=' . json_encode($rule?->created_by));
        return $rule;
    }

    public function test_rule_defaults_and_test_button(): void
    {
        $rule = $this->mkRule([
            'name' => 'QA Rule Created Alert',
            'trigger' => 'lead_created', 'trigger_config' => [],
            'conditions' => [['field'=>'source','value'=>'walk_in']],
            'actions' => [['type'=>'raise_alert','recipient'=>'admins','title'=>'QA: {lead_name} arrived','body'=>'from {project}','severity'=>'info']],
        ]);
        $logsBefore = AutomationLog::count();
        $r = $this->actingAs($this->admin())->postJson('/automation/rules/match', [
            'trigger'=>'lead_created','conditions'=>[['field'=>'source','value'=>'walk_in']]]);
        $this->say('  Test button -> ' . $r->status() . ' count=' . $r->json('count') . ' logsWritten=' . (AutomationLog::count()-$logsBefore)
            . ' firedCount=' . $rule->fresh()->fire_count);

        // rule is off: creating a lead must not fire it
        $lead = $this->mkLead('9101010101');
        $this->say('  lead created while rule OFF: logs=' . AutomationLog::where('rule_id',$rule->id)->count()
            . ' alerts=' . Alert::where('rule_id',$rule->id)->count());

        // switch on and create another
        $this->actingAs($this->admin())->post("/automation/rules/{$rule->id}/toggle", ['is_active'=>true]);
        $lead2 = $this->mkLead('9101010102');
        $logs = AutomationLog::where('rule_id',$rule->id)->get();
        $alerts = Alert::where('rule_id',$rule->id)->get();
        $this->say('  lead created while rule ON: logs=' . $logs->count() . ' [' . $logs->pluck('action')->implode(',') . '/' . $logs->pluck('result')->implode(',') . ']');
        $this->say('  alerts written=' . $alerts->count() . ' titles=' . json_encode($alerts->pluck('title')->all()) . ' recipients=' . json_encode($alerts->pluck('user_id')->all()));
        $this->say('  log row has no actor column: ' . json_encode(array_keys((array) $logs->first()?->getAttributes())));

        // dedupe within 24h — same rule, same lead, raise again
        $lead2->refresh();
        app(\App\Services\Automation\RuleEngine::class)->run($rule->fresh(), $lead2);
        $this->say('  second run on same lead: logs=' . AutomationLog::where('rule_id',$rule->id)->count()
            . ' alerts=' . Alert::where('rule_id',$rule->id)->count() . ' (cooldown should suppress)');
        $this->say('  suppression logged: ' . json_encode(AutomationLog::where('rule_id',$rule->id)->where('action','suppressed')->pluck('result')->all()));
        $this->inv('after automation alert rule');
        $this->assertTrue(true);
    }

    public function test_alert_privacy(): void
    {
        $rule = $this->mkRule([
            'name' => 'QA Rule Alert Everyone',
            'trigger' => 'lead_created', 'trigger_config' => [],
            'conditions' => [],
            'actions' => [['type'=>'raise_alert','recipient'=>'role','recipient_role'=>'salesperson','title'=>'QA privacy {lead_name}','severity'=>'info']],
        ]);
        $this->actingAs($this->admin())->post("/automation/rules/{$rule->id}/toggle", ['is_active'=>true]);
        $lead = $this->mkLead('9101010103');   // owned by the telecaller
        $alerts = Alert::where('rule_id',$rule->id)->get();
        $this->say('  lead owner=' . $lead->assigned_to . ' alerts to salespeople=' . $alerts->count()
            . ' recipients=' . json_encode($alerts->pluck('user_id')->all())
            . ' (a salesperson cannot see this lead, so 0 is correct)');
        $this->assertTrue(true);
    }

    public function test_ping_pong_loop_protection(): void
    {
        $a = $this->mkRule([
            'name'=>'QA Loop A','trigger'=>'stage_changed','trigger_config'=>['stage'=>'not_connected'],
            'conditions'=>[], 'actions'=>[['type'=>'change_stage','stage'=>'connected']]]);
        $b = $this->mkRule([
            'name'=>'QA Loop B','trigger'=>'stage_changed','trigger_config'=>['stage'=>'connected'],
            'conditions'=>[], 'actions'=>[['type'=>'change_stage','stage'=>'not_connected']]]);
        $this->actingAs($this->admin())->post("/automation/rules/{$a->id}/toggle", ['is_active'=>true]);
        $this->actingAs($this->admin())->post("/automation/rules/{$b->id}/toggle", ['is_active'=>true]);

        $lead = $this->mkLead('9101010104');
        $todo = $lead->todos()->where('status','pending')->first();
        $t0 = microtime(true);
        $r = $this->actingAs($this->admin())->post("/todos/{$todo->id}/complete", [
            'stage'=>'not_connected','remarks'=>'kick off the loop','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i')]);
        $secs = round(microtime(true)-$t0, 2);
        $lead->refresh();
        $logs = AutomationLog::whereIn('rule_id',[$a->id,$b->id])->orderBy('id')->get();
        $this->say("  ping-pong: http={$r->status()} took {$secs}s final stage={$lead->stage}");
        foreach ($logs as $l) { $this->say('    log: rule=' . $l->rule_id . ' action=' . $l->action . ' result=' . $l->result . ' err=' . substr((string)$l->error,0,60)); }
        $this->say('  suppression alerts: ' . Alert::where('type','automation_suppressed')->where('lead_id',$lead->id)->count());
        $this->inv('after ping-pong');
        $this->assertTrue(true);
    }

    public function test_whatsapp_queues_not_sends(): void
    {
        $r = $this->actingAs($this->admin())->post('/automation/templates', [
            'name'=>'QA Template','category'=>'utility','body'=>'Hello {first_name}, about {project}. — {owner_name}','is_active'=>true]);
        $tpl = MessageTemplate::where('name','QA Template')->first();
        if ($tpl) $this->templates[] = $tpl->id;
        $this->say('  template created -> ' . $r->status() . ' id=' . $tpl?->id . ' active=' . var_export($tpl?->is_active,true));
        if (!$tpl) { $this->assertTrue(true); return; }
        if (!$tpl->is_active) { $this->actingAs($this->admin())->post("/automation/templates/{$tpl->id}/toggle", ['is_active'=>true]); $tpl->refresh(); }

        $rule = $this->mkRule([
            'name'=>'QA WhatsApp Rule','trigger'=>'lead_created','trigger_config'=>[],
            'conditions'=>[],'actions'=>[['type'=>'queue_whatsapp','template_id'=>$tpl->id]]]);
        $this->actingAs($this->admin())->post("/automation/rules/{$rule->id}/toggle", ['is_active'=>true]);

        $lead = $this->mkLead('9876543210');
        $msg = MessageLog::where('lead_id',$lead->id)->latest('id')->first();
        $this->say('  message row: status=' . $msg?->status . ' mode=' . $msg?->mode . ' to=' . $msg?->to_number . ' sent_at=' . json_encode($msg?->sent_at));
        $this->say('  body: ' . json_encode($msg?->body));
        if ($msg) {
            $url = app(\App\Services\WhatsApp\TemplateRenderer::class)->clickUrl($lead->mobile_number, $msg->body);
            $this->say('  CLICK URL: ' . $url);
            $open = $this->actingAs($this->admin())->postJson("/automation/messages/{$msg->id}/open");
            $this->say('  open endpoint -> ' . $open->status() . ' ' . json_encode($open->json()) . ' status now=' . $msg->fresh()->status);
            $send = $this->actingAs($this->admin())->post("/automation/messages/{$msg->id}/send");
            $this->say('  send-by-API endpoint -> ' . $send->status() . ' msg status=' . $msg->fresh()->status . ' error=' . substr((string)$msg->fresh()->error,0,80));
        }
        $this->inv('after whatsapp rule');
        $this->assertTrue(true);
    }

    public function test_rule_validation_and_non_admin(): void
    {
        foreach ([
            'no name'        => ['name'=>'','trigger'=>'lead_created','conditions'=>[],'actions'=>[['type'=>'change_stage','stage'=>'connected']]],
            'bogus trigger'  => ['name'=>'QA Bogus T','trigger'=>'when_i_say_so','conditions'=>[],'actions'=>[['type'=>'change_stage','stage'=>'connected']]],
            'bogus action'   => ['name'=>'QA Bogus A','trigger'=>'lead_created','conditions'=>[],'actions'=>[['type'=>'delete_everything']]],
            'bogus stage'    => ['name'=>'QA Bogus S','trigger'=>'lead_created','conditions'=>[],'actions'=>[['type'=>'change_stage','stage'=>'nope']]],
            'no actions'     => ['name'=>'QA No Act','trigger'=>'lead_created','conditions'=>[],'actions'=>[]],
            'stage_changed no stage' => ['name'=>'QA No Stage','trigger'=>'stage_changed','trigger_config'=>[],'conditions'=>[],'actions'=>[['type'=>'change_stage','stage'=>'connected']]],
        ] as $label => $payload) {
            $this->actingAs($this->admin())->post('/automation/rules', $payload);
            $rule = AutomationRule::where('name', $payload['name'] ?: 'ZZZ')->first();
            if ($rule) $this->rules[] = $rule->id;
            $this->say(sprintf('  %-24s saved=%s', $label, $rule ? 'YES' : 'no'));
        }
        foreach ([['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $this->say("  $n POST /automation/rules -> " . $this->actingAs($u)->post('/automation/rules', ['name'=>'x'])->status());
            $this->say("  $n POST /automation/rules/match -> " . $this->actingAs($u)->postJson('/automation/rules/match', ['trigger'=>'lead_created'])->status());
            $this->say("  $n POST /automation/templates -> " . $this->actingAs($u)->post('/automation/templates', ['name'=>'x','body'=>'y'])->status());
            $this->say("  $n PUT /automation/whatsapp -> " . $this->actingAs($u)->put('/automation/whatsapp', [])->status());
        }
        $this->assertTrue(true);
    }
}
