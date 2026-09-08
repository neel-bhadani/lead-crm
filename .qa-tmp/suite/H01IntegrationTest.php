<?php
namespace QA;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;

class H01IntegrationTest extends QaCase
{
    private array $trashLead = [];
    protected function tearDown(): void {
        foreach ($this->trashLead as $id) { Todo::where('lead_id',$id)->forceDelete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }

    private function configure(array $over = []): Integration
    {
        $i = Integration::forProvider('facebook');
        $i->mergeSettings(array_merge([
            'page_access_token' => 'FAKE_TOKEN_qa_0000_1234',
            'app_secret'        => 'FAKE_SECRET_qa_abcd',
            'page_id'           => '111222333',
            'default_project_id'=> 2,
            'assign_to_user_id' => $this->tele()->id,
        ], $over));
        $i->is_active = true;
        $i->save();
        $i->ensureVerifyToken();
        return $i->fresh();
    }

    public function test_settings_and_token_never_reaches_browser(): void
    {
        $admin = $this->admin();
        $r = $this->actingAs($admin)->put('/integrations/facebook', [
            'is_active' => true, 'page_id' => '111222333',
            'default_project_id' => 2, 'assign_to_user_id' => $this->tele()->id,
            'page_access_token' => 'FAKE_TOKEN_qa_0000_1234',
            'app_secret' => 'FAKE_SECRET_qa_abcd',
        ]);
        $this->say('  save settings -> ' . $r->status());

        $resp = $this->actingAs($admin)->get('/integrations');
        $html = $resp->getContent();
        foreach (['FAKE_TOKEN_qa_0000_1234','FAKE_SECRET_qa_abcd'] as $secret) {
            $this->say('  page source contains ' . $secret . ': ' . (str_contains($html, $secret) ? 'YES  <-- LEAK' : 'no'));
        }
        $props = $resp->viewData('page')['props'];
        $fb = collect($props['cards'])->firstWhere('provider','facebook');
        $this->say('  card settings prop: ' . json_encode($fb['settings']));
        $this->say('  webhook prop: ' . json_encode($fb['webhook']));
        $this->say('  full props contain secret: ' . (str_contains(json_encode($props), 'FAKE_SECRET_qa_abcd') ? 'YES <-- LEAK' : 'no'));
        // stub providers 404
        $this->say('  PUT /integrations/whatsapp -> ' . $this->actingAs($admin)->put('/integrations/whatsapp', [])->status());
        $this->say('  POST /integrations/instagram/test -> ' . $this->actingAs($admin)->post('/integrations/instagram/test')->status());
        // non-admin
        foreach ([['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $this->say("  $n GET /integrations -> " . $this->actingAs($u)->get('/integrations')->status());
            $this->say("  $n PUT /integrations/facebook -> " . $this->actingAs($u)->put('/integrations/facebook', ['is_active'=>true])->status());
            $this->say("  $n POST /integrations/facebook/test -> " . $this->actingAs($u)->post('/integrations/facebook/test')->status());
        }
        $this->assertTrue(true);
    }

    public function test_send_test_lead(): void
    {
        $this->configure();
        $before = Lead::count();
        $r = $this->actingAs($this->admin())->post('/integrations/facebook/test');
        $after = Lead::count();
        $new = Lead::where('external_id','like','test_%')->orderByDesc('id')->first();
        if ($new) $this->trashLead[] = $new->id;
        $ev = IntegrationEvent::latest('id')->first();
        $this->say('  test lead -> http=' . $r->status() . " leads $before->$after");
        $this->say('  event: ' . json_encode(['result'=>$ev?->result,'msg'=>$ev?->message,'ext'=>$ev?->external_id]));
        if ($new) {
            $this->say('  lead: id=' . $new->id . ' project=' . $new->project_id . ' owner=' . $new->assigned_to
                . ' source=' . $new->source . ' stage=' . $new->stage . ' created_by=' . json_encode($new->created_by)
                . ' mobile=' . $new->mobile_number
                . ' pendingTodos=' . $new->todos()->where('status','pending')->count()
                . ' todoDue=' . $new->todos()->where('status','pending')->value('scheduled_at'));
        }
        $this->inv('after test lead');
        $this->assertTrue(true);
    }

    public function test_test_lead_with_deleted_and_inactive_project(): void
    {
        $admin = $this->admin();
        // inactive project
        $p = Project::find(3);
        $p->forceFill(['is_active'=>false])->save();
        $this->configure(['default_project_id' => 3]);
        $r = $this->actingAs($admin)->post('/integrations/facebook/test');
        $new = Lead::where('project_id',3)->where('external_id','like','test_%')->orderByDesc('id')->first();
        if ($new) $this->trashLead[] = $new->id;
        $this->say('  test lead onto INACTIVE project -> ' . ($new ? 'CREATED id='.$new->id : 'refused') . ' ev=' . json_encode(IntegrationEvent::latest('id')->first()?->message));
        $p->forceFill(['is_active'=>true])->save();

        // deleted user
        $this->configure(['assign_to_user_id' => 99999]);
        $r = $this->actingAs($admin)->post('/integrations/facebook/test');
        $this->say('  test lead with missing assignee -> http=' . $r->status() . ' ev=' . json_encode(IntegrationEvent::latest('id')->first()?->message));

        // integration not ready
        $i = Integration::forProvider('facebook'); $i->is_active = false; $i->save();
        $r = $this->actingAs($admin)->post('/integrations/facebook/test');
        $this->say('  test lead while switched off -> http=' . $r->status());
        $this->configure();
        $this->inv('after project/user edge cases');
        $this->assertTrue(true);
    }

    public function test_webhook_signature_and_repeat(): void
    {
        $i = $this->configure();
        $secret = 'FAKE_SECRET_qa_abcd';
        $body = json_encode(['entry'=>[['changes'=>[['field'=>'leadgen','value'=>['leadgen_id'=>'qa_lg_0001']]]]]]);
        $sig  = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $r = $this->call('POST', '/webhooks/facebook/leads', [], [], [], [
            'CONTENT_TYPE'=>'application/json','HTTP_X_HUB_SIGNATURE_256'=>'sha256=deadbeef'], $body);
        $this->say('  invalid signature -> ' . $r->status() . ' body=' . substr($r->getContent(),0,120));

        $r = $this->call('POST', '/webhooks/facebook/leads', [], [], [], ['CONTENT_TYPE'=>'application/json'], $body);
        $this->say('  no signature header -> ' . $r->status());

        $r = $this->call('POST', '/webhooks/facebook/leads', [], [], [], [
            'CONTENT_TYPE'=>'application/json','HTTP_X_HUB_SIGNATURE_256'=>$sig], $body);
        $this->say('  valid signature -> ' . $r->status() . ' (job runs sync; Graph call will fail with a fake token)');
        $this->say('  event: ' . json_encode(IntegrationEvent::latest('id')->first()?->only(['result','external_id','message'])));

        // repeat delivery of the same leadgen_id
        $r = $this->call('POST', '/webhooks/facebook/leads', [], [], [], [
            'CONTENT_TYPE'=>'application/json','HTTP_X_HUB_SIGNATURE_256'=>$sig], $body);
        $this->say('  repeat leadgen_id -> ' . $r->status() . ' event=' . json_encode(IntegrationEvent::latest('id')->first()?->only(['result','message'])));

        // verification handshake
        $token = $i->setting('verify_token');
        $r = $this->get('/webhooks/facebook/leads?hub_mode=subscribe&hub_verify_token=' . urlencode($token) . '&hub_challenge=CHAL123');
        $this->say('  verify correct token -> ' . $r->status() . ' body=' . substr($r->getContent(),0,120));
        $r = $this->get('/webhooks/facebook/leads?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=CHAL123');
        $this->say('  verify wrong token -> ' . $r->status() . ' body=' . substr($r->getContent(),0,120));
        $r = $this->get('/webhooks/whatsapp/leads?hub_verify_token=x&hub_challenge=y');
        $this->say('  verify unbuilt provider -> ' . $r->status());
        $this->assertTrue(true);
    }
}
