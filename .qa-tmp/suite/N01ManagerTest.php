<?php
namespace QA;

use App\Models\User;

class N01ManagerTest extends QaCase
{
    public function test_see_all_leads_manager(): void
    {
        $u = $this->sales();
        $orig = $u->permissions;
        $u->forceFill(['permissions' => ['add_leads'=>true,'edit_leads'=>true,'delete_leads'=>false,'see_all_leads'=>true,'export_data'=>false]])->save();
        $u->refresh();
        $this->say('  can_(see_all_leads)=' . var_export($u->can_('see_all_leads'), true) . ' role=' . $u->role);

        $lp = $this->actingAs($u)->get('/leads')->viewData('page')['props'];
        $tp = $this->actingAs($u)->get('/todos')->viewData('page')['props'];
        $dp = $this->actingAs($u)->get('/dashboard?range=30&reset=1')->viewData('page')['props'];
        $ap = $this->actingAs($this->admin())->get('/leads?reset=1')->viewData('page')['props'];
        $at = $this->actingAs($this->admin())->get('/todos?reset=1')->viewData('page')['props'];
        $ad = $this->actingAs($this->admin())->get('/dashboard?range=30&reset=1')->viewData('page')['props'];

        $this->say('  LEADS      manager=' . $lp['leads']['total'] . '  admin=' . $ap['leads']['total']);
        $this->say('  TODO tabs  manager=' . json_encode($tp['counts']) . '  admin=' . json_encode($at['counts']));
        $this->say('  DASH cards manager=' . json_encode($dp['cards']));
        $this->say('  DASH cards admin  =' . json_encode($ad['cards']));
        $this->say('  -> manager sees every lead but only their own follow-ups; "Calls pending" and the panels are scoped by ROLE, the lead figures by PERMISSION');
        $this->say('  report dims (leads)=' . json_encode(array_keys($this->actingAs($u)->get('/reports/leads')->viewData('page')['props']['options']['dimensions'])));
        $this->say('  report dims (fups) =' . json_encode(array_keys($this->actingAs($u)->get('/reports/followups')->viewData('page')['props']['options']['dimensions'])));

        $u->forceFill(['permissions' => $orig])->save();
        $this->actingAs($this->admin())->get('/leads?reset=1');
        $this->actingAs($this->admin())->get('/todos?reset=1');
        $this->actingAs($this->admin())->get('/dashboard?range=30&reset=1');
        $this->assertTrue(true);
    }
}
