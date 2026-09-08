<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;

class C01FollowUpTest extends QaCase
{
    private array $trash = [];
    private function errs(): array { $e = session('errors'); return $e instanceof \Illuminate\Support\ViewErrorBag ? $e->getBag('default')->all() : []; }
    private function mk(array $o = []): Lead {
        $d = array_merge([
            'first_name'=>'QAFu','last_name'=>'Probe','mobile_number'=>'9555500001','project_id'=>1,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i'),
        ], $o);
        $this->actingAs($this->admin())->post('/leads', $d);
        $l = Lead::where('mobile_number', $d['mobile_number'])->firstOrFail();
        $this->trash[] = $l->id;
        return $l;
    }
    protected function tearDown(): void {
        foreach ($this->trash as $id) { Todo::where('lead_id',$id)->forceDelete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }

    public function test_log_call_every_outcome(): void
    {
        foreach (array_keys(config('crm.stages')) as $i => $stage) {
            $lead = $this->mk(['mobile_number' => '95556000' . str_pad((string)$i,2,'0',STR_PAD_LEFT)]);
            $todo = $lead->todos()->where('status','pending')->first();
            $payload = [
                'stage' => $stage, 'remarks' => 'QA log',
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            ];
            if ($stage === 'lost') { $payload['reason'] = 'budget'; $payload['follow_up_at']=''; $payload['follow_up_type']=''; }
            if ($stage === 'booking_done') { $payload['booked_unit'] = 'B-1'; $payload['follow_up_at']=''; $payload['follow_up_type']=''; }
            $r = $this->actingAs($this->admin())->post("/todos/{$todo->id}/complete", $payload);
            $lead->refresh();
            $this->say(sprintf('  outcome=%-22s http=%d stage=%s pending=%d owner=%d role=%s',
                $stage, $r->status(), $lead->stage, $lead->todos()->where('status','pending')->count(),
                $lead->assigned_to, $lead->assigned_role));
        }
        $this->inv('after logging every outcome');
        $this->assertTrue(true);
    }

    public function test_past_dates_rejected_everywhere(): void
    {
        $lead = $this->mk(['mobile_number'=>'9555700001']);
        $todo = $lead->todos()->where('status','pending')->first();

        // reschedule to past
        $r = $this->actingAs($this->admin())->put("/todos/{$todo->id}", [
            'lead_id'=>$lead->id,'type'=>'call','scheduled_at'=>now()->subDays(3)->format('Y-m-d\TH:i'),'remarks'=>'x']);
        $this->say('reschedule to past -> ' . $r->status() . ' still at ' . $todo->fresh()->scheduled_at);

        // make it overdue then reschedule to past again
        $todo->forceFill(['scheduled_at' => now()->subDays(5)])->save();
        $r = $this->actingAs($this->admin())->put("/todos/{$todo->id}", [
            'lead_id'=>$lead->id,'type'=>'call','scheduled_at'=>now()->subDay()->format('Y-m-d\TH:i'),'remarks'=>'x']);
        $this->say('overdue task, reschedule to past -> ' . $r->status() . ' at ' . $todo->fresh()->scheduled_at);
        $r = $this->actingAs($this->admin())->put("/todos/{$todo->id}", [
            'lead_id'=>$lead->id,'type'=>'call','scheduled_at'=>now()->addDays(1)->format('Y-m-d\TH:i'),'remarks'=>'x']);
        $this->say('overdue task, reschedule to future -> ' . $r->status() . ' at ' . $todo->fresh()->scheduled_at);

        // complete with past next follow-up
        $r = $this->actingAs($this->admin())->post("/todos/{$todo->id}/complete", [
            'stage'=>'connected','remarks'=>'x','follow_up_type'=>'call','follow_up_at'=>now()->subHour()->format('Y-m-d\TH:i')]);
        $this->say('log call with past next date -> ' . $r->status() . ' stage now ' . $lead->fresh()->stage);
        $this->inv('after past-date probes');
        $this->assertTrue(true);
    }

    public function test_two_or_zero_pending(): void
    {
        $lead = $this->mk(['mobile_number'=>'9555800001']);
        // try to add a second pending follow-up through the API
        $r = $this->actingAs($this->admin())->post('/todos', [
            'lead_id'=>$lead->id,'type'=>'call','scheduled_at'=>now()->addDays(3)->format('Y-m-d\TH:i'),'remarks'=>'second']);
        $this->say('second pending todo via POST /todos -> ' . $r->status() . ' pending now=' . $lead->todos()->where('status','pending')->count());

        // admin cancels the only pending follow-up on an OPEN lead
        $todo = $lead->todos()->where('status','pending')->first();
        $r = $this->actingAs($this->admin())->delete("/todos/{$todo->id}");
        $this->say('DELETE /todos/{id} on open lead -> ' . $r->status()
            . ' lead stage=' . $lead->fresh()->stage
            . ' pending=' . $lead->todos()->where('status','pending')->count());
        $this->inv('after cancelling the only pending follow-up');

        // non-admin cannot cancel
        $lead2 = $this->mk(['mobile_number'=>'9555800002']);
        $t2 = $lead2->todos()->where('status','pending')->first();
        $r = $this->actingAs($this->tele())->delete("/todos/{$t2->id}");
        $this->say('DELETE /todos as telecaller -> ' . $r->status());
        $this->assertTrue(true);
    }

    public function test_completing_someone_elses_todo(): void
    {
        $lead = $this->mk(['mobile_number'=>'9555900001']);  // owned by telecaller
        $todo = $lead->todos()->where('status','pending')->first();
        foreach ([['sales',$this->sales()],['tele',$this->tele()]] as [$n,$u]) {
            $r = $this->actingAs($u)->post("/todos/{$todo->id}/complete", [
                'stage'=>'connected','remarks'=>'x','follow_up_type'=>'call',
                'follow_up_at'=>now()->addDays(2)->format('Y-m-d\TH:i')]);
            $this->say("  complete as $n -> " . $r->status() . ' todo status=' . $todo->fresh()->status);
        }
        // double-submit: complete an already completed todo
        $r = $this->actingAs($this->admin())->post("/todos/{$todo->id}/complete", [
            'stage'=>'connected','remarks'=>'x','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDays(2)->format('Y-m-d\TH:i')]);
        $this->say('  double-submit complete -> ' . $r->status());
        $this->inv('after ownership probes');
        $this->assertTrue(true);
    }

    public function test_conflict_endpoint(): void
    {
        $r = $this->actingAs($this->sales())->postJson('/follow-ups/check-conflict', [
            'assigned_to' => $this->tele()->id, 'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s')]);
        $this->say('conflict as salesperson about telecaller: ' . json_encode($r->json()));
        $r = $this->actingAs($this->admin())->postJson('/follow-ups/check-conflict', [
            'assigned_to' => 99999, 'scheduled_at' => 'not-a-date']);
        $this->say('conflict bad input: ' . $r->status() . ' ' . json_encode($r->json()));
        $this->assertTrue(true);
    }
}
