<?php

namespace Tests\Feature\QA;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

class AuthAndRolesProbe extends QaBase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->boot(); }

    /** Errors from the session, whatever shape the bag is in. */
    protected function errs(): array
    {
        $e = session('errors');

        if ($e === null) return [];
        if (is_array($e)) return $e;

        return $e->getBag('default')->messages();
    }

    public function test_login_matrix(): void
    {
        $out = [];

        // email
        $r = $this->post('/login', ['login' => 'ann@qa.test', 'password' => 'password']);
        $out['email + right password'] = $r->status() . ' redirect=' . ($r->headers->get('Location') ?? '-');
        $this->post('/logout');

        // mobile
        $r = $this->post('/login', ['login' => $this->tele->mobile_number, 'password' => 'password']);
        $out['mobile + right password'] = $r->status() . ' redirect=' . ($r->headers->get('Location') ?? '-');
        $this->post('/logout');

        // wrong password
        $r = $this->from('/login')->post('/login', ['login' => 'ann@qa.test', 'password' => 'nope']);
        $out['wrong password'] = $r->status() . ' errors=' . json_encode($this->errs());

        RateLimiter::clear(strtolower('ann@qa.test') . '|127.0.0.1');

        // unknown user — same message?
        $r = $this->from('/login')->post('/login', ['login' => 'ghost@qa.test', 'password' => 'nope']);
        $out['unknown user'] = $r->status() . ' errors=' . json_encode($this->errs());

        RateLimiter::clear(strtolower('ghost@qa.test') . '|127.0.0.1');

        // inactive user with the correct password
        $off = $this->mkUser('telecaller', 'Off', false);
        $r = $this->from('/login')->post('/login', ['login' => 'off@qa.test', 'password' => 'password']);
        $out['inactive user + right password'] = $r->status()
            . ' authed=' . var_export(auth()->check(), true)
            . ' errors=' . json_encode($this->errs());

        RateLimiter::clear(strtolower('off@qa.test') . '|127.0.0.1');

        // empty fields
        $r = $this->from('/login')->post('/login', ['login' => '', 'password' => '']);
        $out['empty fields'] = $r->status() . ' errors=' . json_encode(array_keys($this->errs()));

        // rate limiting: 5 allowed, 6th blocked
        $key = 'ratelimit@qa.test|127.0.0.1';
        RateLimiter::clear($key);
        $msgs = [];
        for ($i = 1; $i <= 7; $i++) {
            $r = $this->from('/login')->post('/login', ['login' => 'ratelimit@qa.test', 'password' => 'x']);
            $msgs[$i] = ($this->errs()['login'][0] ?? null);
        }
        $out['rate limit after 5'] = json_encode([5 => $msgs[5], 6 => $msgs[6]]);
        RateLimiter::clear($key);

        // signed out, direct URL
        $this->post('/logout');
        foreach (['/dashboard', '/leads', '/todos', '/users', '/automation', '/projects', '/integrations', '/alerts', '/reports/leads'] as $u) {
            $out["guest GET $u"] = $this->get($u)->status();
        }

        // removed Breeze routes
        foreach (['/register', '/forgot-password', '/reset-password', '/profile', '/verify-email'] as $u) {
            $out["removed route GET $u"] = $this->get($u)->status();
        }

        fwrite(STDERR, "\n=== LOGIN MATRIX ===\n");
        foreach ($out as $k => $v) fwrite(STDERR, sprintf("  %-34s %s\n", $k, $v));

        $this->assertTrue(true);
    }

    public function test_route_authorization_matrix(): void
    {
        $lead = $this->mkLead();
        $this->mkTodo($lead);
        $partner = \App\Models\ChannelPartner::create(['name' => 'Firm A', 'type' => 'firm', 'phone' => '9800000000', 'is_active' => true]);

        $routes = [
            ['GET',    '/dashboard'],
            ['GET',    '/leads'],
            ['GET',    '/todos'],
            ['GET',    '/reports/leads'],
            ['GET',    '/reports/followups'],
            ['GET',    '/alerts'],
            ['GET',    '/users'],
            ['POST',   '/users'],
            ['GET',    '/channel-partners'],
            ['POST',   '/channel-partners/quick'],
            ['GET',    '/projects'],
            ['POST',   '/projects'],
            ['GET',    '/projects/1'],
            ['DELETE', '/projects/1'],
            ['GET',    '/integrations'],
            ['PUT',    '/integrations/facebook'],
            ['POST',   '/integrations/facebook/test'],
            ['GET',    '/automation'],
            ['GET',    '/automation/guide'],
            ['POST',   '/automation/rules'],
            ['POST',   '/automation/rules/match'],
            ['PUT',    '/automation/whatsapp'],
            ['POST',   '/leads'],
            ['PUT',    '/leads/' . $lead->id],
            ['DELETE', '/leads/' . $lead->id],
            ['GET',    '/leads/' . $lead->id],
            ['POST',   '/todos'],
            ['DELETE', '/todos/1'],
        ];

        $rows = [];
        foreach ($routes as [$verb, $uri]) {
            $line = [];
            foreach (['admin' => $this->admin, 'telecaller' => $this->tele, 'salesperson' => $this->sales] as $label => $u) {
                $r = $this->actingAs($u)->call($verb, $uri, [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $line[$label] = $r->status();
            }
            $rows["$verb $uri"] = $line;
        }

        fwrite(STDERR, "\n=== ROUTE x ROLE STATUS MATRIX (422=validation reached, 403=blocked) ===\n");
        fwrite(STDERR, sprintf("  %-34s %6s %11s %12s\n", 'route', 'admin', 'telecaller', 'salesperson'));
        foreach ($rows as $route => $line) {
            fwrite(STDERR, sprintf("  %-34s %6d %11d %12d\n", $route, $line['admin'], $line['telecaller'], $line['salesperson']));
        }

        $this->assertTrue(true);
    }

    public function test_deactivated_user_with_a_live_session(): void
    {
        $out = [];
        $this->actingAs($this->tele);

        $out['before: GET /todos'] = $this->get('/todos')->status();

        $this->tele->update(['is_active' => false]);

        $out['after deactivation: GET /todos'] = $this->get('/todos')->status();
        $out['after deactivation: GET /leads'] = $this->get('/leads')->status();
        $out['after deactivation: GET /dashboard'] = $this->get('/dashboard')->status();

        $lead = $this->mkLead(['assigned_to' => $this->tele->id]);
        $out['after deactivation: POST /todos'] = $this->post('/todos', [
            'lead_id' => $lead->id, 'type' => 'call', 'scheduled_at' => '2026-09-20 10:00',
        ])->status();

        $admin = $this->admin;
        $admin->update(['is_active' => false]);
        $out['deactivated admin: GET /users'] = $this->actingAs($admin)->get('/users')->status();

        fwrite(STDERR, "\n=== DEACTIVATED USER, LIVE SESSION ===\n");
        foreach ($out as $k => $v) fwrite(STDERR, sprintf("  %-42s %s\n", $k, $v));

        $this->assertTrue(true);
    }
}
