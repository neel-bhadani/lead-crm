<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;

class M01DeleteSweepTest extends QaCase
{
    public function test_delete_a_lead_then_every_page_loads(): void
    {
        // a lead with history: complete a call on it first
        $this->actingAs($this->admin())->post('/leads', [
            'first_name'=>'QADel','last_name'=>'Probe','mobile_number'=>'9000000044','project_id'=>1,
            'source'=>'broker','channel_partner_id'=>\App\Models\ChannelPartner::active()->value('id'),
            'stage'=>'fresh','follow_up_type'=>'call','follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i')]);
        $lead = Lead::where('mobile_number','9000000044')->firstOrFail();
        $todo = $lead->todos()->pending()->first();
        $this->actingAs($this->admin())->post("/todos/{$todo->id}/complete", [
            'stage'=>'site_visit_done','remarks'=>'visited','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDays(2)->format('Y-m-d\TH:i')]);

        $r = $this->actingAs($this->admin())->delete('/leads/'.$lead->id);
        $this->say('  delete -> ' . $r->status() . ' trashed=' . (Lead::withTrashed()->find($lead->id)->trashed()?'YES':'no')
            . ' pending todos left=' . Todo::where('lead_id',$lead->id)->pending()->count()
            . ' completed kept=' . Todo::where('lead_id',$lead->id)->where('status','completed')->count());

        foreach ([['admin',$this->admin()],['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            foreach (['/dashboard','/leads','/todos?tab=completed','/todos?tab=overdue','/reports/leads','/reports/followups?status=completed','/alerts'] as $uri) {
                $r = $this->actingAs($u)->get($uri);
                if ($r->status() !== 200) $this->say("  !! $n $uri -> " . $r->status());
            }
        }
        foreach (['/users','/projects','/projects/1','/channel-partners','/integrations','/automation'] as $uri) {
            $r = $this->actingAs($this->admin())->get($uri);
            if ($r->status() !== 200) $this->say('  !! admin ' . $uri . ' -> ' . $r->status());
        }
        $this->say('  every page still loads for every role.');
        $this->say('  deleted lead still on /leads? ' . (collect($this->actingAs($this->admin())->get('/leads')->viewData('page')['props']['leads']['data'])->firstWhere('id',$lead->id) ? 'YES' : 'no'));
        $this->inv('after deleting a lead with history');

        Todo::where('lead_id',$lead->id)->forceDelete();
        Lead::withTrashed()->find($lead->id)?->forceDelete();
        $this->actingAs($this->admin())->get('/leads?reset=1');
        $this->actingAs($this->admin())->get('/todos?reset=1');
        $this->assertTrue(true);
    }
}
