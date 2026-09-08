<?php

namespace Tests\Feature\QA;

use App\Models\Lead;
use App\Models\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

class InvariantAndTodoProbe extends QaBase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->boot(); }

    private function say(string $s): void { fwrite(STDERR, $s . "\n"); }

    public function test_rate_limiting_really_bites(): void
    {
        $key = 'rl@qa.test|127.0.0.1';
        RateLimiter::clear($key);

        $this->say("\n=== RATE LIMIT (5 allowed) ===");
        for ($i = 1; $i <= 7; $i++) {
            $this->from('/login')->post('/login', ['login' => 'rl@qa.test', 'password' => 'x']);
            $msg = $this->errFor('login');
            $this->say(sprintf('  attempt %d: %s', $i, $msg ?? '(none)'));
        }
        RateLimiter::clear($key);
        $this->assertTrue(true);
    }

    public function test_deactivated_user_can_still_write(): void
    {
        $lead = $this->mkLead(['assigned_to' => $this->tele->id]);

        $this->actingAs($this->tele);
        $this->tele->update(['is_active' => false]);

        $before = Todo::count();
        $r = $this->post('/todos', [
            'lead_id' => $lead->id, 'type' => 'call',
            'scheduled_at' => '2026-09-20 10:00', 'remarks' => 'written while deactivated',
        ]);
        $after = Todo::count();

        $this->say("\n=== DEACTIVATED USER WRITE ===");
        $this->say("  POST /todos status={$r->status()}  todos before=$before after=$after");
        $this->say('  errors: ' . json_encode($this->errs()));
        $this->say('  row written: ' . var_export(Todo::where('remarks', 'written while deactivated')->exists(), true));

        // and can they complete a call (moving a stage)?
        $todo = Todo::where('lead_id', $lead->id)->where('status', 'pending')->first();
        if ($todo) {
            $r2 = $this->post("/todos/{$todo->id}/complete", [
                'stage' => 'connected', 'remarks' => 'call logged while deactivated',
                'follow_up_at' => '2026-09-25 10:00', 'follow_up_type' => 'call',
            ]);
            $this->say("  POST complete status={$r2->status()} lead stage now=" . $lead->fresh()->stage);
        }

        $this->assertTrue(true);
    }

    public function test_admin_cancelling_a_pending_todo_breaks_the_invariant(): void
    {
        $lead = $this->mkLead(['stage' => 'connected']);
        $todo = $this->mkTodo($lead);

        $this->say("\n=== ADMIN CANCELS THE ONLY PENDING FOLLOW-UP ON AN OPEN LEAD ===");
        $this->say('  invariant before: ' . Lead::open()->doesntHave('pendingTodo')->count());

        $r = $this->actingAs($this->admin)->delete("/todos/{$todo->id}");

        $broken = Lead::open()->doesntHave('pendingTodo')->count();
        $this->say("  DELETE /todos/{$todo->id} status={$r->status()}");
        $this->say("  todo status now: " . $todo->fresh()->status);
        $this->say("  lead stage: " . $lead->fresh()->stage . ' (open=' . var_export(! $lead->fresh()->isTerminal(), true) . ')');
        $this->say("  INVARIANT open leads with no pending todo: $broken   <-- must be 0");

        $this->assertTrue(true);
    }

    public function test_two_pending_todos_possible(): void
    {
        $lead = $this->mkLead(['stage' => 'connected']);
        $this->mkTodo($lead);

        $this->say("\n=== CAN A LEAD END UP WITH TWO PENDING FOLLOW-UPS? ===");

        // via the form: TodoRequest::withValidator should refuse
        $r = $this->actingAs($this->admin)->post('/todos', [
            'lead_id' => $lead->id, 'type' => 'call', 'scheduled_at' => '2026-09-20 10:00',
        ]);
        $this->say('  POST /todos second pending: status=' . $r->status()
            . ' errors=' . json_encode($this->errs()));
        $this->say('  pending count: ' . Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());

        $this->assertTrue(true);
    }

    public function test_past_dates_rejected_everywhere(): void
    {
        $lead = $this->mkLead(['stage' => 'connected']);
        $todo = $this->mkTodo($lead, ['scheduled_at' => now()->subDays(5)]);   // already overdue

        $this->say("\n=== PAST DATES ===");

        // 1. new follow-up in the past
        $l2 = $this->mkLead(['stage' => 'connected']);
        $r = $this->actingAs($this->admin)->post('/todos', [
            'lead_id' => $l2->id, 'type' => 'call', 'scheduled_at' => '2026-09-01 10:00',
        ]);
        $this->say('  create in past:            ' . $r->status() . ' ' . json_encode($this->errs()));

        // 2. reschedule an already-overdue task into the past
        $r = $this->actingAs($this->admin)->put("/todos/{$todo->id}", [
            'lead_id' => $lead->id, 'type' => 'call', 'scheduled_at' => '2026-09-02 10:00',
        ]);
        $this->say('  reschedule overdue->past:  ' . $r->status() . ' ' . json_encode($this->errs()));

        // 3. reschedule an already-overdue task into the future (must be allowed)
        $r = $this->actingAs($this->admin)->put("/todos/{$todo->id}", [
            'lead_id' => $lead->id, 'type' => 'call', 'scheduled_at' => '2026-09-20 10:00',
        ]);
        $this->say('  reschedule overdue->future:' . $r->status() . ' ' . json_encode($this->errs()));

        // 4. lead form with a past follow-up
        $r = $this->actingAs($this->admin)->post('/leads', $this->leadPayload([
            'mobile_number' => '9811111111', 'follow_up_at' => '2026-09-01 10:00',
        ]));
        $this->say('  lead form past follow-up:  ' . $r->status() . ' ' . json_encode($this->errs()));

        // 5. complete a call booking the next one in the past
        $t2 = Todo::where('lead_id', $lead->id)->where('status', 'pending')->first();
        $r = $this->actingAs($this->admin)->post("/todos/{$t2->id}/complete", [
            'stage' => 'connected', 'remarks' => 'x',
            'follow_up_at' => '2026-09-01 10:00', 'follow_up_type' => 'call',
        ]);
        $this->say('  log call, next in past:    ' . $r->status() . ' ' . json_encode($this->errs()));

        // 6. exactly now (boundary)
        $r = $this->actingAs($this->admin)->post('/todos', [
            'lead_id' => $l2->id, 'type' => 'call', 'scheduled_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $this->say('  exactly now:               ' . $r->status() . ' ' . json_encode($this->errs()));

        $this->assertTrue(true);
    }

    public function test_completing_a_call_with_every_outcome_stage(): void
    {
        $this->say("\n=== LOG A CALL WITH EVERY OUTCOME STAGE ===");

        foreach (array_keys(config('crm.stages')) as $stage) {
            $lead = $this->mkLead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
            $todo = $this->mkTodo($lead);

            $payload = ['stage' => $stage, 'remarks' => 'outcome ' . $stage];

            if (! in_array($stage, config('crm.terminal_stages'), true)) {
                $payload['follow_up_at'] = '2026-09-25 10:00';
                $payload['follow_up_type'] = 'call';
            }
            if ($stage === 'lost')         $payload['reason'] = 'budget';
            if ($stage === 'booking_done') $payload['booked_unit'] = 'A-101';

            $r = $this->actingAs($this->admin)->post("/todos/{$todo->id}/complete", $payload);

            $lead->refresh();
            $pending = Todo::where('lead_id', $lead->id)->where('status', 'pending')->count();
            $inv = Lead::open()->doesntHave('pendingTodo')->count();

            $this->say(sprintf('  %-22s status=%d stage=%-20s pending=%d invariant=%d %s',
                $stage, $r->status(), $lead->stage, $pending, $inv,
                $r->status() === 302 && ! $this->errs() ? '' : json_encode($this->errs())));
        }

        $this->assertTrue(true);
    }

    public function test_handover_at_site_visit_scheduled(): void
    {
        $this->say("\n=== HANDOVER AT site_visit_scheduled ===");

        $lead = $this->mkLead(['stage' => 'connected', 'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller']);
        $todo = $this->mkTodo($lead);

        $r = $this->actingAs($this->tele)->post("/todos/{$todo->id}/complete", [
            'stage' => 'site_visit_scheduled', 'remarks' => 'visit booked',
            'follow_up_at' => '2026-09-20 10:00', 'follow_up_type' => 'site_visit',
        ]);

        $lead->refresh();
        $next = Todo::where('lead_id', $lead->id)->where('status', 'pending')->first();

        $this->say('  status=' . $r->status());
        $this->say('  lead owner: ' . ($lead->assigned_to === $this->sales->id ? 'salesperson (handed over)' : 'user ' . $lead->assigned_to));
        $this->say('  assigned_role: ' . $lead->assigned_role);
        $this->say('  next todo owner: ' . ($next?->assigned_to === $this->sales->id ? 'salesperson' : 'user ' . $next?->assigned_to));
        $this->say('  warning flash: ' . json_encode(session('warning')));
        $this->say('  invariant: ' . Lead::open()->doesntHave('pendingTodo')->count());

        // no active salesperson -> what happens?
        $this->sales->update(['is_active' => false]);
        $lead2 = $this->mkLead(['stage' => 'connected', 'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller']);
        $todo2 = $this->mkTodo($lead2);
        $r2 = $this->actingAs($this->tele)->post("/todos/{$todo2->id}/complete", [
            'stage' => 'site_visit_scheduled', 'remarks' => 'visit booked',
            'follow_up_at' => '2026-09-20 10:00', 'follow_up_type' => 'site_visit',
        ]);
        $lead2->refresh();
        $this->say('  no active salesperson: status=' . $r2->status()
            . ' owner stays=' . ($lead2->assigned_to === $this->tele->id ? 'telecaller' : (string) $lead2->assigned_to)
            . ' invariant=' . Lead::open()->doesntHave('pendingTodo')->count());

        $this->assertTrue(true);
    }

    protected function errs(): array
    {
        $e = session('errors');
        if ($e === null) return [];
        if (is_array($e)) return $e;
        return $e->getBag('default')->messages();
    }

    protected function errFor(string $key): ?string
    {
        return $this->errs()[$key][0] ?? null;
    }
}
