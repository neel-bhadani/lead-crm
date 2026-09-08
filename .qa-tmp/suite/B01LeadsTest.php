<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;

class B01LeadsTest extends QaCase
{
    private function errs(): array
    {
        $e = session('errors');
        return $e instanceof \Illuminate\Support\ViewErrorBag ? $e->getBag('default')->all() : [];
    }

    private function base(array $over = []): array
    {
        return array_merge([
            'first_name' => 'QA', 'last_name' => 'Probe',
            'mobile_number' => '9111100002', 'project_id' => 1,
            'source' => 'walk_in', 'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ], $over);
    }

    public function test_source_defaults_stage_and_owner(): void
    {
        $admin = $this->admin();
        $created = [];
        foreach (array_keys(config('crm.sources')) as $i => $src) {
            $data = $this->base([
                'mobile_number' => '92221000' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                'source' => $src,
                'first_name' => 'QASrc' . $i,
            ]);
            if ($src === 'broker') { $data['channel_partner_id'] = \App\Models\ChannelPartner::active()->value('id'); }
            $r = $this->actingAs($admin)->post('/leads', $data);
            $lead = Lead::where('mobile_number', $data['mobile_number'])->first();
            $this->say(sprintf('  source=%-14s status=%d stage=%s owner=%s role=%s pending=%d errs=%s',
                $src, $r->status(), $lead?->stage, $lead?->assigned_to, $lead?->assigned_role,
                $lead ? $lead->todos()->where('status','pending')->count() : -1,
                json_encode($this->errs())));
            if ($lead) $created[] = $lead->id;
        }
        $this->inv('after 8 source leads');
        foreach ($created as $id) { Todo::where('lead_id',$id)->delete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        $this->assertTrue(true);
    }

    public function test_hostile_inputs(): void
    {
        $admin = $this->admin();
        $cases = [
            'empty everything'      => ['first_name'=>'','last_name'=>'','mobile_number'=>'','project_id'=>'','source'=>'','stage'=>'','follow_up_type'=>'','follow_up_at'=>''],
            'max length names'      => ['first_name'=>str_repeat('a',101),'last_name'=>str_repeat('b',101)],
            'exact max names'       => ['first_name'=>str_repeat('a',100),'last_name'=>str_repeat('b',100),'mobile_number'=>'9333300001'],
            'mobile 9 digits'       => ['mobile_number'=>'911110000'],
            'mobile 11 digits'      => ['mobile_number'=>'91111000022'],
            'mobile letters'        => ['mobile_number'=>'abcdefghij'],
            'mobile negative'       => ['mobile_number'=>'-911110000'],
            'project nonexistent'   => ['project_id'=>999999],
            'project string'        => ['project_id'=>'; DROP TABLE leads;--'],
            'stage bogus'           => ['stage'=>'super_stage'],
            'source bogus'          => ['source'=>'telepathy'],
            'email bad'             => ['email'=>'not-an-email'],
            'xss name'              => ['first_name'=>'<script>alert(1)</script>','mobile_number'=>'9333300002'],
            'unicode name'          => ['first_name'=>'राहुल 🙂','last_name'=>'मेहता','mobile_number'=>'9333300003'],
            'past follow-up'        => ['follow_up_at'=>now()->subDay()->format('Y-m-d\TH:i'),'mobile_number'=>'9333300004'],
            'follow-up 1 min ago'   => ['follow_up_at'=>now()->subMinute()->format('Y-m-d\TH:i'),'mobile_number'=>'9333300005'],
            'follow-up far future'  => ['follow_up_at'=>'2099-12-31T10:00','mobile_number'=>'9333300006'],
            'lost without reason'   => ['stage'=>'lost','follow_up_at'=>'','follow_up_type'=>'','mobile_number'=>'9333300007'],
            'lost with bad reason'  => ['stage'=>'lost','reason'=>'aliens','follow_up_at'=>'','follow_up_type'=>'','mobile_number'=>'9333300008'],
            'booked without unit'   => ['stage'=>'booking_done','follow_up_at'=>'','follow_up_type'=>'','mobile_number'=>'9333300009'],
            'broker w/o partner'    => ['source'=>'broker','mobile_number'=>'9333300010'],
        ];
        $made = [];
        foreach ($cases as $label => $over) {
            $r = $this->actingAs($admin)->post('/leads', $this->base($over));
            $errs = $this->errs();
            $mob = $this->base($over)['mobile_number'];
            $lead = $mob ? Lead::where('mobile_number', $mob)->first() : null;
            if ($lead) $made[] = $lead->id;
            $this->say(sprintf('  %-22s -> %d created=%s errs=%s', $label, $r->status(), $lead ? 'YES' : 'no', json_encode($errs)));
        }
        $this->inv('after hostile lead inputs');
        foreach ($made as $id) { Todo::where('lead_id',$id)->delete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        $this->assertTrue(true);
    }
}
