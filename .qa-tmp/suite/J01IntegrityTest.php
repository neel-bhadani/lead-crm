<?php
namespace QA;

use App\Models\Alert;
use App\Models\Lead;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;

class J01IntegrityTest extends QaCase
{
    public function test_data_integrity_audit(): void
    {
        $mismatch = DB::table('leads')
            ->join('todos', function ($j) { $j->on('todos.lead_id','=','leads.id'); })
            ->whereNull('leads.deleted_at')
            ->whereNotNull('todos.outcome_stage')
            ->whereNotNull('todos.completed_at')
            ->select('leads.id','leads.stage', DB::raw('max(todos.completed_at) as last'))
            ->groupBy('leads.id','leads.stage')->get();
        $bad = 0;
        foreach ($mismatch as $row) {
            $latest = Todo::where('lead_id',$row->id)->whereNotNull('outcome_stage')
                ->orderByDesc('completed_at')->orderByDesc('id')->value('outcome_stage');
            if ($latest !== $row->stage) { $bad++; if ($bad <= 8) $this->say("  lead {$row->id}: stage={$row->stage} latest history={$latest}"); }
        }
        $this->say('leads whose stage disagrees with their latest history row: ' . $bad . ' / ' . $mismatch->count());

        $this->say('todos with a missing lead row: ' . Todo::whereNotIn('lead_id', DB::table('leads')->pluck('id'))->count());
        $this->say('todos whose lead is soft-deleted: ' . Todo::whereIn('lead_id', DB::table('leads')->whereNotNull('deleted_at')->pluck('id'))->count());
        $this->say('  of which PENDING: ' . Todo::pending()->whereIn('lead_id', DB::table('leads')->whereNotNull('deleted_at')->pluck('id'))->count());
        $this->say('leads with null assigned_to: ' . Lead::whereNull('assigned_to')->count());
        $this->say('todos with null assigned_to: ' . Todo::whereNull('assigned_to')->count());
        $this->say('open leads with 0 pending: ' . Lead::open()->doesntHave('pendingTodo')->count());
        $multi = Lead::open()->withCount(['todos as p'=>fn($q)=>$q->where('status','pending')])->get()->filter(fn($l)=>$l->p>1);
        $this->say('open leads with >1 pending: ' . $multi->count() . ' ' . json_encode($multi->pluck('id')->all()));
        $this->say('terminal leads holding a pending: ' . Lead::whereIn('stage',config('crm.terminal_stages'))->whereHas('todos',fn($q)=>$q->where('status','pending'))->count());
        $this->say('duplicate history rows (same lead+outcome+completed_at): ' .
            DB::table('todos')->select('lead_id','outcome_stage','completed_at', DB::raw('count(*) c'))
              ->whereNotNull('outcome_stage')->groupBy('lead_id','outcome_stage','completed_at')
              ->havingRaw('count(*) > 1')->get()->count());
        $this->say('alerts pointing at a missing/deleted lead: ' . Alert::whereNotNull('lead_id')
            ->whereNotIn('lead_id', DB::table('leads')->whereNull('deleted_at')->pluck('id'))->count());
        $this->say('alerts whose recipient cannot see the lead: ' . Alert::with('user','lead')->get()
            ->filter(fn($a) => $a->lead && $a->user && ! $a->user->can_('see_all_leads') && $a->lead->assigned_to !== $a->user_id)->count());
        $this->say('leads with assigned_role not matching owner role: ' . Lead::with('owner')->get()
            ->filter(fn($l) => $l->owner && $l->owner->role !== 'admin' && $l->assigned_role !== $l->owner->role)->count());
        $this->say('leads at booking_done with no booking_date: ' . Lead::where('stage','booking_done')->whereNull('booking_date')->count());
        $this->say('leads at booking_done with no booked_unit: ' . Lead::where('stage','booking_done')->whereNull('booked_unit')->count());
        $this->say('leads at lost with no reason: ' . Lead::where('stage','lost')->whereNull('reason')->count());
        $this->assertTrue(true);
    }

    public function test_query_counts_per_page(): void
    {
        foreach ([
            '/dashboard','/leads','/todos','/reports/leads','/reports/followups',
            '/users','/channel-partners','/projects','/projects/1','/integrations','/automation','/alerts',
        ] as $uri) {
            DB::flushQueryLog(); DB::enableQueryLog();
            $r = $this->actingAs($this->admin())->get($uri);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->say(sprintf('  %-22s %d  %d queries', $uri, $r->status(), $n));
        }
        // second page of a paginated list
        foreach (['/leads?page=2','/todos?tab=completed&page=2','/users?page=2'] as $uri) {
            DB::flushQueryLog(); DB::enableQueryLog();
            $r = $this->actingAs($this->admin())->get($uri);
            $this->say(sprintf('  %-22s %d  %d queries', $uri, $r->status(), count(DB::getQueryLog())));
            DB::disableQueryLog();
        }
        $this->assertTrue(true);
    }

    public function test_timezone(): void
    {
        $this->say('app.timezone=' . config('app.timezone') . ' now()=' . now()->toDateTimeString() . ' today()=' . today()->toDateString());
        $this->say('DB @@time_zone=' . json_encode(DB::select('select @@session.time_zone as tz')[0]->tz)
            . ' DB NOW()=' . json_encode(DB::select('select NOW() as n')[0]->n));
        $this->say('PHP date_default_timezone=' . date_default_timezone_get());
        // IST midnight boundary: a todo scheduled at 23:59 IST today must be "today"
        $edge = today()->copy()->setTime(23,59);
        $this->say('today 23:59 counts as dueToday: ' . var_export($edge->isSameDay(today()), true));
        $this->assertTrue(true);
    }
}
