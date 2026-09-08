<?php
namespace QA;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\Todo;
use App\Services\IncomingLeadService;

class H02ImportTest extends QaCase
{
    private array $trash = [];
    protected function tearDown(): void {
        foreach ($this->trash as $id) { Todo::where('lead_id',$id)->forceDelete(); Lead::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }

    public function test_idempotency_and_repeat_enquiry(): void
    {
        $i = Integration::forProvider('facebook');
        $i->mergeSettings(['page_access_token'=>'FAKE','app_secret'=>'FAKE','page_id'=>'1',
            'default_project_id'=>2,'assign_to_user_id'=>$this->tele()->id]);
        $i->is_active = true; $i->save();

        $svc = app(IncomingLeadService::class);
        $attrs = ['first_name'=>'QAImport','last_name'=>'One','mobile_number'=>'9888800001','email'=>'qa.import@example.com'];

        $a = $svc->import($i, 'qa_ext_0001', $attrs, 'facebook');
        if ($a['lead']) $this->trash[] = $a['lead']->id;
        $this->say('  first import: ' . $a['result'] . ' | ' . $a['message'] . ' pending=' . ($a['lead']?->todos()->where('status','pending')->count()));

        $b = $svc->import($i, 'qa_ext_0001', $attrs, 'facebook');
        $this->say('  same leadgen_id: ' . $b['result'] . ' | ' . $b['message']);

        $c = $svc->import($i, 'qa_ext_0002', $attrs, 'facebook');
        if ($c['lead'] && !in_array($c['lead']->id, $this->trash)) $this->trash[] = $c['lead']->id;
        $this->say('  same mobile, new leadgen_id: ' . $c['result'] . ' | ' . $c['message']);

        // soft-deleted lead then reimport
        $a['lead']->todos()->update(['status'=>'cancelled']);
        $a['lead']->delete();
        $d = $svc->import($i, 'qa_ext_0003', $attrs, 'facebook');
        $this->say('  after soft-delete, new leadgen_id: ' . $d['result'] . ' | ' . $d['message']);
        $a['lead']->restore();
        $a['lead']->todos()->where('status','cancelled')->latest('id')->limit(1)->update(['status'=>'pending']);
        $this->inv('after import probes');
        $this->assertTrue(true);
    }
}
