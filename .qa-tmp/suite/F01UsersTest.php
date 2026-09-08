<?php
namespace QA;

use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;

class F01UsersTest extends QaCase
{
    private array $trash = [];
    private function errs(): array { $e = session('errors'); return $e instanceof \Illuminate\Support\ViewErrorBag ? $e->getBag('default')->all() : []; }
    protected function tearDown(): void {
        foreach ($this->trash as $id) { User::withTrashed()->find($id)?->forceDelete(); }
        parent::tearDown();
    }
    private function base(array $o = []): array {
        return array_merge([
            'first_name'=>'QA','last_name'=>'Staff','email'=>'qa.staff@example.com',
            'mobile_number'=>'9666600001','role'=>'telecaller','password'=>'password123',
            'password_confirmation'=>'password123','is_active'=>true,
        ], $o);
    }

    public function test_create_each_role_and_dupes(): void
    {
        foreach (['telecaller','salesperson','admin'] as $i => $role) {
            $d = $this->base(['role'=>$role,'email'=>"qa.$role@example.com",'mobile_number'=>'966660000'.($i+1)]);
            $r = $this->actingAs($this->admin())->post('/users', $d);
            $u = User::withTrashed()->where('email', $d['email'])->first();
            if ($u) $this->trash[] = $u->id;
            $this->say(sprintf('  create role=%-12s http=%d created=%s actualRole=%s', $role, $r->status(), $u?'YES':'no', $u?->role));
        }
        // duplicate email / mobile
        $r = $this->actingAs($this->admin())->post('/users', $this->base(['email'=>'admin@crm.test','mobile_number'=>'9666600009']));
        $this->say('  duplicate email -> created=' . (User::where('mobile_number','9666600009')->exists()?'YES':'no'));
        $r = $this->actingAs($this->admin())->post('/users', $this->base(['email'=>'qa.new@example.com','mobile_number'=>'9820000001']));
        $this->say('  duplicate mobile -> created=' . (User::where('email','qa.new@example.com')->exists()?'YES':'no'));
        // hostile
        foreach ([
            'short password' => ['password'=>'123','password_confirmation'=>'123','email'=>'qa.p@example.com','mobile_number'=>'9666600011'],
            'mismatched confirm' => ['password_confirmation'=>'different','email'=>'qa.q@example.com','mobile_number'=>'9666600012'],
            'bad email' => ['email'=>'nope','mobile_number'=>'9666600013'],
            'mobile 11' => ['email'=>'qa.r@example.com','mobile_number'=>'96666000133'],
            'empty names' => ['first_name'=>'','last_name'=>'','email'=>'qa.s@example.com','mobile_number'=>'9666600014'],
        ] as $label=>$o) {
            $d = $this->base($o);
            $this->actingAs($this->admin())->post('/users', $d);
            $u = User::withTrashed()->where('email',$d['email'])->first();
            if ($u) $this->trash[] = $u->id;
            $this->say(sprintf('  %-20s created=%s', $label, $u?'YES':'no'));
        }
        $this->assertTrue(true);
    }

    public function test_edit_without_password_and_role_change(): void
    {
        $d = $this->base(['email'=>'qa.edit@example.com','mobile_number'=>'9666600020','role'=>'salesperson']);
        $this->actingAs($this->admin())->post('/users', $d);
        $u = User::where('email','qa.edit@example.com')->firstOrFail();
        $this->trash[] = $u->id;
        $before = $u->password;

        $r = $this->actingAs($this->admin())->put('/users/'.$u->id, [
            'first_name'=>'QAEdited','last_name'=>'Staff','email'=>'qa.edit@example.com',
            'mobile_number'=>'9666600020','role'=>'salesperson','is_active'=>true,
            'password'=>'', 'password_confirmation'=>'']);
        $u->refresh();
        $this->say('  edit w/o password: http=' . $r->status() . ' name=' . $u->first_name . ' passwordUnchanged=' . ($before === $u->password ? 'YES':'NO'));

        // give them a lead at an advanced stage, then demote to telecaller
        $lead = Lead::first();
        $origOwner = $lead->assigned_to; $origRole = $lead->assigned_role; $origStage = $lead->stage;
        $lead->forceFill(['assigned_to'=>$u->id,'assigned_role'=>'salesperson','stage'=>'site_visit_done'])->save();
        $r = $this->actingAs($this->admin())->put('/users/'.$u->id, [
            'first_name'=>'QAEdited','last_name'=>'Staff','email'=>'qa.edit@example.com',
            'mobile_number'=>'9666600020','role'=>'telecaller','is_active'=>true]);
        $u->refresh(); $lead->refresh();
        $this->say('  demote while holding advanced lead: http=' . $r->status() . ' role=' . $u->role
            . ' lead.assigned_role=' . $lead->assigned_role . ' lead.stage=' . $lead->stage . ' (mismatch is the risk)');

        // deactivate with open leads and no handover
        $lead->forceFill(['stage'=>'connected'])->save();
        $r = $this->actingAs($this->admin())->put('/users/'.$u->id, [
            'first_name'=>'QAEdited','last_name'=>'Staff','email'=>'qa.edit@example.com',
            'mobile_number'=>'9666600020','role'=>'telecaller','is_active'=>false]);
        $u->refresh();
        $this->say('  deactivate w/ open leads, no handover: http=' . $r->status() . ' still active=' . (int)$u->is_active . ' errs=' . json_encode($this->errs()));

        // deactivate WITH handover to a telecaller
        $r = $this->actingAs($this->admin())->put('/users/'.$u->id, [
            'first_name'=>'QAEdited','last_name'=>'Staff','email'=>'qa.edit@example.com',
            'mobile_number'=>'9666600020','role'=>'telecaller','is_active'=>false,
            'handover_to'=>$this->tele()->id]);
        $u->refresh(); $lead->refresh();
        $this->say('  deactivate w/ handover: http=' . $r->status() . ' active=' . (int)$u->is_active . ' lead owner now=' . $lead->assigned_to);

        $lead->forceFill(['assigned_to'=>$origOwner,'assigned_role'=>$origRole,'stage'=>$origStage])->save();
        $this->inv('after user deactivation');
        $this->assertTrue(true);
    }

    public function test_delete_guards(): void
    {
        $admin = $this->admin();
        // delete yourself
        $r = $this->actingAs($admin)->delete('/users/'.$admin->id);
        $this->say('  delete self -> ' . $r->status() . ' deleted=' . (User::withTrashed()->find($admin->id)->trashed()?'YES':'no') . ' errs=' . json_encode($this->errs()));
        // delete the last admin
        $this->say('  active admins = ' . User::active()->where('role','admin')->count());
        // deactivate self
        $r = $this->actingAs($admin)->put('/users/'.$admin->id, [
            'first_name'=>$admin->first_name,'last_name'=>$admin->last_name,'email'=>$admin->email,
            'mobile_number'=>$admin->mobile_number,'role'=>'admin','is_active'=>false]);
        $this->say('  deactivate self -> ' . $r->status() . ' active=' . (int)$admin->fresh()->is_active . ' errs=' . json_encode($this->errs()));
        // non-admin hitting user routes
        foreach ([['tele',$this->tele()],['sales',$this->sales()]] as [$n,$u]) {
            $this->say("  $n POST /users -> " . $this->actingAs($u)->post('/users', $this->base())->status());
            $this->say("  $n PUT /users/1 -> " . $this->actingAs($u)->put('/users/1', $this->base())->status());
            $this->say("  $n DELETE /users/3 -> " . $this->actingAs($u)->delete('/users/3')->status());
        }
        $this->assertTrue(true);
    }
}
