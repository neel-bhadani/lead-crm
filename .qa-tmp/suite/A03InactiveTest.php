<?php
namespace QA;

use App\Models\Lead;

class A03InactiveTest extends QaCase
{
    public function test_deactivated_user_live_session_can_still_write(): void
    {
        $u = $this->sales2();
        $u->forceFill(['is_active' => false])->save();

        $r = $this->actingAs($u)->post('/leads', [
            'first_name' => 'QAInactive', 'last_name' => 'Probe',
            'mobile_number' => '9111100001', 'project_id' => 1,
            'source' => 'walk_in', 'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ]);
        $lead = Lead::where('mobile_number', '9111100001')->first();
        $this->say('deactivated user POST /leads => ' . $r->status() . ' lead created: ' . ($lead ? 'YES id=' . $lead->id : 'no'));
        $this->say('  session errors: ' . json_encode(session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('default')->all() : null));

        if ($lead) {
            $r2 = $this->actingAs($u)->put('/leads/' . $lead->id, [
                'first_name' => 'QAInactiveEdited', 'last_name' => 'Probe',
                'mobile_number' => '9111100001', 'project_id' => 1,
                'source' => 'walk_in', 'stage' => 'fresh',
            ]);
            $this->say('  deactivated user PUT /leads/{id} => ' . $r2->status() . ' name now ' . $lead->fresh()->first_name);
            $lead->todos()->forceDelete();
            $lead->forceDelete();
        }
        $u->forceFill(['is_active' => true])->save();
        $this->inv('after inactive-user probe');
        $this->assertTrue(true);
    }
}
