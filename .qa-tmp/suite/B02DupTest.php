<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;

class B02DupTest extends QaCase
{
    private array $trash = [];
    private function errs(): array { $e = session('errors'); return $e instanceof \Illuminate\Support\ViewErrorBag ? $e->getBag('default')->all() : []; }
    private function base(array $o = []): array {
        return array_merge([
            'first_name'=>'QADup','last_name'=>'Probe','mobile_number'=>'9444400001','project_id'=>1,
            'source'=>'walk_in','stage'=>'fresh','follow_up_type'=>'call',
            'follow_up_at'=>now()->addDay()->format('Y-m-d\TH:i'),
        ], $o);
    }
    private function mk(array $o = []): ?Lead {
        $this->actingAs($this->admin())->post('/leads', $this->base($o));
        $l = Lead::where('mobile_number', $this->base($o)['mobile_number'])->where('project_id', $this->base($o)['project_id'])->first();
        if ($l) $this->trash[] = $l->id;
        return $l;
    }
    protected function tearDown(): void {
        foreach ($this->trash as $id) { Todo::where('lead_id',$id)->forceDelete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }

    public function test_duplicates(): void
    {
        $a = $this->mk();
        $this->say('first lead: ' . ($a?->id ?? 'FAILED'));

        // same mobile, same project
        $r = $this->actingAs($this->admin())->post('/leads', $this->base());
        $this->say('same mobile same project -> ' . $r->status() . ' errs=' . json_encode($this->errs()));

        // same mobile, different project
        $b = $this->mk(['project_id' => 2]);
        $this->say('same mobile different project -> created: ' . ($b ? 'YES id='.$b->id : 'no'));

        // check-duplicate endpoint
        $r = $this->actingAs($this->admin())->postJson('/leads/check-duplicate', ['mobile_number'=>'9444400001','project_id'=>1]);
        $this->say('check-duplicate (admin): ' . json_encode($r->json()));
        $r = $this->actingAs($this->sales())->postJson('/leads/check-duplicate', ['mobile_number'=>'9444400001','project_id'=>1]);
        $this->say('check-duplicate (sales, not owner): ' . json_encode($r->json()));

        // soft-delete then retry
        $a->todos()->update(['status'=>'cancelled']);
        $a->delete();
        $r = $this->actingAs($this->admin())->post('/leads', $this->base());
        $this->say('after soft-delete, same number -> ' . $r->status() . ' errs=' . json_encode($this->errs()));
        $r = $this->actingAs($this->admin())->postJson('/leads/check-duplicate', ['mobile_number'=>'9444400001','project_id'=>1]);
        $this->say('check-duplicate after delete: ' . json_encode($r->json()));
        $this->inv('after duplicate probes');
        $this->assertTrue(true);
    }

    public function test_terminal_creation_and_history(): void
    {
        $l = $this->mk(['mobile_number'=>'9444400010','stage'=>'booking_done','booked_unit'=>'A-101','follow_up_at'=>'','follow_up_type'=>'']);
        $this->say('created at booking_done: ' . ($l?->id ?? 'FAILED') . ' stage=' . $l?->stage . ' booking_date=' . $l?->booking_date
            . ' pending=' . ($l ? $l->todos()->where('status','pending')->count() : -1)
            . ' history=' . ($l ? $l->todos()->whereNotNull('outcome_stage')->count() : -1));

        $l2 = $this->mk(['mobile_number'=>'9444400011','stage'=>'site_visit_done']);
        $this->say('created at site_visit_done: id=' . $l2?->id . ' pending=' . ($l2 ? $l2->todos()->where('status','pending')->count() : -1)
            . ' history=' . ($l2 ? $l2->todos()->whereNotNull('outcome_stage')->count() : -1)
            . ' role=' . $l2?->assigned_role);

        $l3 = $this->mk(['mobile_number'=>'9444400012','stage'=>'site_visit_scheduled']);
        $this->say('created at site_visit_scheduled (handover stage): id=' . $l3?->id . ' owner=' . $l3?->assigned_to . ' role=' . $l3?->assigned_role);
        $this->inv('after terminal/backfill creation');
        $this->assertTrue(true);
    }

    public function test_cross_user_access(): void
    {
        $l = $this->mk(['mobile_number'=>'9444400020']);   // owned by telecaller (id 2)
        $this->say('lead ' . $l->id . ' owner=' . $l->assigned_to);
        foreach ([['sales',$this->sales()],['tele',$this->tele()],['admin',$this->admin()]] as [$n,$u]) {
            $r = $this->actingAs($u)->get('/leads/' . $l->id);
            $this->say("  GET /leads/{$l->id} as $n -> " . $r->status());
            $r = $this->actingAs($u)->put('/leads/' . $l->id, $this->base(['mobile_number'=>'9444400020','first_name'=>'Hacked'.$n]));
            $this->say("  PUT /leads/{$l->id} as $n -> " . $r->status() . ' name=' . $l->fresh()->first_name);
            $r = $this->actingAs($u)->delete('/leads/' . $l->id);
            $this->say("  DELETE /leads/{$l->id} as $n -> " . $r->status() . ' trashed=' . (Lead::withTrashed()->find($l->id)?->trashed() ? 'YES':'no'));
        }
        $this->inv('after cross-user probes');
        $this->assertTrue(true);
    }
}
