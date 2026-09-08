<?php
namespace QA;

class A02GuestTest extends QaCase
{
    public function test_guest_is_redirected(): void
    {
        foreach (['/dashboard','/leads','/todos','/users','/projects','/automation','/integrations','/reports/leads','/alerts','/'] as $uri) {
            $r = $this->get($uri);
            $this->say(sprintf('  GET %-22s %d -> %s', $uri, $r->status(), $r->headers->get('location')));
        }
        foreach ([['POST','/leads'],['POST','/todos'],['DELETE','/leads/1'],['POST','/automation/rules']] as [$m,$u]) {
            $r = $this->call($m, $u);
            $this->say(sprintf('  %-6s %-22s %d -> %s', $m, $u, $r->status(), $r->headers->get('location')));
        }
        $this->assertTrue(true);
    }

    public function test_login_paths(): void
    {
        // email login
        $r = $this->post('/login', ['login' => 'admin@crm.test', 'password' => '123456789']);
        $this->say('email login: ' . $r->status() . ' -> ' . $r->headers->get('location'));
        $this->post('/logout');

        $r = $this->post('/login', ['login' => '9820000002', 'password' => '123456789']);
        $this->say('mobile login: ' . $r->status() . ' -> ' . $r->headers->get('location'));
        $this->post('/logout');

        $r = $this->post('/login', ['login' => 'admin@crm.test', 'password' => 'wrongpass']);
        $this->say('wrong password: ' . $r->status() . ' err=' . json_encode((session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('default')->all() : session('errors'))));

        $r = $this->post('/login', ['login' => 'nobody@crm.test', 'password' => 'wrongpass']);
        $this->say('unknown user: ' . $r->status() . ' err=' . json_encode((session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('default')->all() : session('errors'))));

        $r = $this->post('/login', ['login' => '', 'password' => '']);
        $this->say('empty: ' . $r->status() . ' err=' . json_encode((session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('default')->all() : session('errors'))));

        $r = $this->post('/login', ['login' => str_repeat('a', 5000) . '@x.com', 'password' => 'x']);
        $this->say('very long login: ' . $r->status());
        $this->assertTrue(true);
    }

    public function test_rate_limit(): void
    {
        \Illuminate\Support\Facades\RateLimiter::clear(\Illuminate\Support\Str::lower('admin@crm.test') . '|127.0.0.1');
        for ($i = 1; $i <= 7; $i++) {
            $r = $this->post('/login', ['login' => 'admin@crm.test', 'password' => 'nope' . $i]);
            $errs = json_encode(session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('default')->all() : session('errors'));
            $this->say("attempt $i: " . $r->status() . ' | ' . $errs);
        }
        \Illuminate\Support\Facades\RateLimiter::clear(\Illuminate\Support\Str::lower('admin@crm.test') . '|127.0.0.1');
        $this->assertTrue(true);
    }

    public function test_inactive_user_cannot_login_and_live_session(): void
    {
        $u = $this->u('sales2@crm.test');
        $u->forceFill(['is_active' => false])->save();
        $r = $this->post('/login', ['login' => 'sales2@crm.test', 'password' => '123456789']);
        $this->say('inactive login: ' . $r->status() . ' err=' . json_encode((session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('default')->all() : session('errors'))));

        // live session while deactivated
        $r = $this->actingAs($u)->get('/leads');
        $this->say('inactive live session /leads: ' . $r->status());
        $r = $this->actingAs($u)->get('/dashboard');
        $this->say('inactive live session /dashboard: ' . $r->status());
        $r = $this->actingAs($u)->post('/leads', []);
        $this->say('inactive live session POST /leads: ' . $r->status());
        $u->forceFill(['is_active' => true])->save();
        $this->assertTrue(true);
    }
}
