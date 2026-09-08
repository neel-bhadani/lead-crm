<?php
namespace QA;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;

class G01PartnerProjectTest extends QaCase
{
    private array $trashP = []; private array $trashProj = []; private array $trashLead = [];
    private function errs(): array { $e = session('errors'); return $e instanceof \Illuminate\Support\ViewErrorBag ? $e->getBag('default')->all() : []; }
    protected function tearDown(): void {
        foreach ($this->trashLead as $id) { Todo::where('lead_id',$id)->forceDelete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        foreach ($this->trashP as $id) { ChannelPartner::withTrashed()->find($id)?->forceDelete(); }
        foreach ($this->trashProj as $id) { Project::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }

    public function test_partner_shapes_and_quick_create(): void
    {
        $admin = $this->admin();
        // firm
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA Firm Alpha','type'=>'firm','phone'=>'022 2222 3333']);
        $this->say('  create firm -> ' . $r->status() . ' ' . json_encode($r->json()));
        $firm = ChannelPartner::where('name','QA Firm Alpha')->first(); if ($firm) $this->trashP[] = $firm->id;
        // broker under firm
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA Broker Under','type'=>'broker','parent_id'=>$firm?->id,'phone'=>'9876543210']);
        $this->say('  broker under firm -> ' . $r->status());
        $b1 = ChannelPartner::where('name','QA Broker Under')->first(); if ($b1) $this->trashP[] = $b1->id;
        // individual broker
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA Solo Broker','type'=>'broker','phone'=>'9876543211']);
        $this->say('  individual broker -> ' . $r->status());
        $b2 = ChannelPartner::where('name','QA Solo Broker')->first(); if ($b2) $this->trashP[] = $b2->id;
        // duplicate name
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA firm alpha','type'=>'firm','phone'=>'0222222444']);
        $this->say('  duplicate (case/space variant) -> ' . $r->status() . ' ' . json_encode($r->json()));
        // firm with a parent
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA Bad Firm','type'=>'firm','parent_id'=>$firm?->id,'phone'=>'0222222555']);
        $this->say('  firm with a parent -> ' . $r->status() . ' ' . json_encode($r->json()));
        // broker under a broker
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA Nested','type'=>'broker','parent_id'=>$b2?->id,'phone'=>'0222222666']);
        $this->say('  broker under a broker -> ' . $r->status() . ' ' . json_encode($r->json()));
        // bad phone
        $r = $this->actingAs($admin)->postJson('/channel-partners/quick', ['name'=>'QA BadPhone','type'=>'broker','phone'=>'abc']);
        $this->say('  bad phone -> ' . $r->status());
        // near-match check
        $r = $this->actingAs($admin)->postJson('/channel-partners/check-name', ['name'=>'QA Firm Alfa']);
        $this->say('  near-match check -> ' . $r->status() . ' ' . json_encode($r->json()));
        // telecaller (no add_leads) cannot create or enumerate
        $this->say('  tele quick create -> ' . $this->actingAs($this->tele())->postJson('/channel-partners/quick', ['name'=>'QA Tele','type'=>'broker','phone'=>'9876543212'])->status());
        $this->say('  tele check-name -> ' . $this->actingAs($this->tele())->postJson('/channel-partners/check-name', ['name'=>'QA'])->status());
        $this->say('  sales quick create -> ' . $this->actingAs($this->sales())->postJson('/channel-partners/quick', ['name'=>'QA Sales Broker','type'=>'broker','phone'=>'9876543213'])->status());
        $sb = ChannelPartner::where('name','QA Sales Broker')->first(); if ($sb) $this->trashP[] = $sb->id;

        // delete a firm holding an active broker
        $r = $this->actingAs($admin)->delete('/channel-partners/'.$firm->id);
        $this->say('  delete firm with active broker -> ' . $r->status() . ' trashed=' . (ChannelPartner::withTrashed()->find($firm->id)->trashed()?'YES':'no'));

        // merge b2 into b1
        $r = $this->actingAs($admin)->post('/channel-partners/'.$b2->id.'/merge', ['target_id'=>$b1->id]);
        $this->say('  merge broker into broker -> ' . $r->status() . ' source trashed=' . (ChannelPartner::withTrashed()->find($b2->id)->trashed()?'YES':'no'));
        // merge into itself
        $r = $this->actingAs($admin)->post('/channel-partners/'.$b1->id.'/merge', ['target_id'=>$b1->id]);
        $this->say('  merge into itself -> ' . $r->status() . ' errs=' . json_encode($this->errs()));
        // non-admin merge / update / delete
        foreach ([['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $this->say("  $n PUT partner -> " . $this->actingAs($u)->put('/channel-partners/'.$b1->id, ['name'=>'x','type'=>'broker','phone'=>'9876543210'])->status());
            $this->say("  $n DELETE partner -> " . $this->actingAs($u)->delete('/channel-partners/'.$b1->id)->status());
            $this->say("  $n MERGE partner -> " . $this->actingAs($u)->post('/channel-partners/'.$b1->id.'/merge', ['target_id'=>$firm->id])->status());
        }
        $this->assertTrue(true);
    }

    public function test_projects(): void
    {
        $admin = $this->admin();
        $r = $this->actingAs($admin)->post('/projects', ['name'=>'QA Test Project','type'=>'residential','location'=>'Nowhere','is_active'=>true]);
        $p = Project::where('name','QA Test Project')->first(); if ($p) $this->trashProj[] = $p->id;
        $this->say('  create project -> ' . $r->status() . ' id=' . $p?->id);
        // duplicate name
        $r = $this->actingAs($admin)->post('/projects', ['name'=>'QA Test Project','type'=>'commercial']);
        $this->say('  duplicate name -> created twice? ' . Project::where('name','QA Test Project')->count());
        // bad type
        $r = $this->actingAs($admin)->post('/projects', ['name'=>'QA Bad Type','type'=>'industrial']);
        $this->say('  bad type -> created=' . (Project::where('name','QA Bad Type')->exists()?'YES':'no'));
        // delete empty project
        $r = $this->actingAs($admin)->delete('/projects/'.$p->id);
        $this->say('  delete empty project -> ' . $r->status() . ' trashed=' . (Project::withTrashed()->find($p->id)->trashed()?'YES':'no'));

        // deactivate a project with leads, then check the add-lead dropdown
        $live = Project::find(1);
        $this->actingAs($admin)->put('/projects/1', ['name'=>$live->name,'type'=>$live->type,'location'=>$live->location,'is_active'=>false]);
        $props = $this->actingAs($admin)->get('/leads')->viewData('page')['props'];
        $ids = array_column($props['options']['projects'] ?? [], 'id');
        $this->say('  project 1 deactivated: appears in add-lead dropdown? ' . (in_array(1,$ids)?'YES':'no') . ' dropdown=' . json_encode($ids));
        // can a lead still be filed against it by posting directly?
        $r = $this->actingAs($admin)->post('/leads', [
            'first_name'=>'QAInactiveProj','last_name'=>'Probe','mobile_number'=>'9777700001','project_id'=>1,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call','follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i')]);
        $l = Lead::where('mobile_number','9777700001')->first(); if ($l) $this->trashLead[] = $l->id;
        $this->say('  POST lead onto inactive project -> ' . ($l ? 'CREATED id='.$l->id : 'refused'));
        $this->actingAs($admin)->put('/projects/1', ['name'=>$live->name,'type'=>$live->type,'location'=>$live->location,'is_active'=>true]);

        // delete a project WITH leads (server-side refusal)
        $before = Lead::withTrashed()->where('project_id',1)->count();
        $r = $this->actingAs($admin)->delete('/projects/1');
        $after = Lead::withTrashed()->where('project_id',1)->count();
        $this->say('  delete project WITH leads -> ' . $r->status() . ' trashed=' . (Project::withTrashed()->find(1)->trashed()?'YES':'no')
            . " leads before=$before after=$after");
        // non-admin
        foreach ([['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $this->say("  $n POST /projects -> " . $this->actingAs($u)->post('/projects', ['name'=>'X','type'=>'residential'])->status());
            $this->say("  $n DELETE /projects/2 -> " . $this->actingAs($u)->delete('/projects/2')->status());
            $this->say("  $n PUT /projects/2 -> " . $this->actingAs($u)->put('/projects/2', ['name'=>'X','type'=>'residential'])->status());
        }
        $this->inv('after project probes');
        $this->assertTrue(true);
    }

    public function test_lead_onto_soft_deleted_project(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/projects', ['name'=>'QA Ghost Project','type'=>'residential','is_active'=>true]);
        $g = Project::where('name','QA Ghost Project')->firstOrFail();
        $this->trashProj[] = $g->id;
        $this->actingAs($admin)->delete('/projects/'.$g->id);
        $this->say('  ghost project trashed=' . (Project::withTrashed()->find($g->id)->trashed()?'YES':'no'));
        $r = $this->actingAs($admin)->post('/leads', [
            'first_name'=>'QAGhost','last_name'=>'Probe','mobile_number'=>'9777700009','project_id'=>$g->id,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call','follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i')]);
        $l = Lead::where('mobile_number','9777700009')->first();
        if ($l) $this->trashLead[] = $l->id;
        $this->say('  POST lead onto SOFT-DELETED project -> ' . ($l ? 'CREATED id='.$l->id.' project rel='.json_encode($l->project?->name) : 'refused'));
        if ($l) {
            $props = $this->actingAs($admin)->get('/leads')->viewData('page')['props'];
            $row = collect($props['leads']['data'])->firstWhere('id', $l->id);
            $this->say('  leads page renders it: project=' . json_encode($row['project'] ?? null));
        }
        $this->assertTrue(true);
    }
}
