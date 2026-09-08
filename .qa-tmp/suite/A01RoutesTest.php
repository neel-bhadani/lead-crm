<?php
namespace QA;

class A01RoutesTest extends QaCase
{
    public function test_route_matrix(): void
    {
        $routes = [
            ['GET','/dashboard'],['GET','/leads'],['GET','/todos'],
            ['GET','/reports/leads'],['GET','/reports/followups'],
            ['GET','/users'],['GET','/channel-partners'],['GET','/projects'],
            ['GET','/projects/1'],
            ['GET','/integrations'],['GET','/automation'],['GET','/automation/guide'],
            ['GET','/alerts'],
        ];
        foreach ([['admin',$this->admin()],['telecaller',$this->tele()],['salesperson',$this->sales()]] as [$label,$u]) {
            $this->say("--- $label ---");
            foreach ($routes as [$m,$uri]) {
                $r = $this->actingAs($u)->call($m, $uri);
                $this->say(sprintf('  %-6s %-24s %d', $m, $uri, $r->status()));
            }
        }
        $this->say('--- guest ---');
        foreach ($routes as [$m,$uri]) {
            $r = $this->call($m, $uri);
            $this->say(sprintf('  %-6s %-24s %d -> %s', $m, $uri, $r->status(), $r->headers->get('location')));
        }
        $this->assertTrue(true);
    }
}
