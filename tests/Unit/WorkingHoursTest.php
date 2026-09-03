<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Services\FollowUpScheduler;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The clamp on its own. No database, no HTTP — just "given this moment and this
 * config, when is the follow-up due".
 */
class WorkingHoursTest extends TestCase
{
    private FollowUpScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduler = new FollowUpScheduler();

        config([
            'crm.working_hours' => ['start' => 9, 'end' => 18],
            'crm.working_days'  => [1, 2, 3, 4, 5, 6, 7],
            'crm.holidays'      => [],
        ]);
    }

    private function clamp(string $when): string
    {
        return $this->scheduler
            ->withinWorkingHours(Carbon::parse($when))
            ->format('D d M Y, H:i');
    }

    /* ---------------- the four rules ---------------- */

    public function test_a_time_inside_working_hours_is_left_alone(): void
    {
        // 2026-09-02 is a Wednesday
        $this->assertSame('Wed 02 Sep 2026, 14:00', $this->clamp('2026-09-02 14:00'));
        $this->assertSame('Wed 02 Sep 2026, 09:00', $this->clamp('2026-09-02 09:00'));
        $this->assertSame('Wed 02 Sep 2026, 17:59', $this->clamp('2026-09-02 17:59'));
    }

    public function test_before_opening_moves_to_the_start_of_the_same_day(): void
    {
        $this->assertSame('Wed 02 Sep 2026, 09:00', $this->clamp('2026-09-02 06:30'));
        $this->assertSame('Wed 02 Sep 2026, 09:00', $this->clamp('2026-09-02 00:00'));
    }

    public function test_closing_time_itself_is_already_shut(): void
    {
        $this->assertSame('Thu 03 Sep 2026, 09:00', $this->clamp('2026-09-02 18:00'));
    }

    public function test_after_closing_jumps_to_the_next_opening_not_to_the_next_free_hour(): void
    {
        $this->assertSame('Thu 03 Sep 2026, 09:00', $this->clamp('2026-09-02 22:00'));
        $this->assertSame('Thu 03 Sep 2026, 09:00', $this->clamp('2026-09-02 23:59'));
    }

    public function test_a_closed_weekday_moves_to_the_next_open_day(): void
    {
        config(['crm.working_days' => [1, 2, 3, 4, 5]]);   // Mon-Fri

        // 2026-09-05 is a Saturday, 2026-09-06 a Sunday
        $this->assertSame('Mon 07 Sep 2026, 09:00', $this->clamp('2026-09-05 11:00'));
        $this->assertSame('Mon 07 Sep 2026, 09:00', $this->clamp('2026-09-06 11:00'));
    }

    /** The chained case from the brief: Saturday 11 PM in a Mon-Fri office. */
    public function test_a_saturday_night_in_a_mon_to_fri_office_becomes_monday_morning(): void
    {
        config(['crm.working_days' => [1, 2, 3, 4, 5]]);

        $this->assertSame('Mon 07 Sep 2026, 09:00', $this->clamp('2026-09-05 23:00'));
    }

    public function test_a_holiday_moves_to_the_next_open_day(): void
    {
        config(['crm.holidays' => ['2026-09-03']]);

        $this->assertSame('Fri 04 Sep 2026, 09:00', $this->clamp('2026-09-03 11:00'));
    }

    public function test_consecutive_holidays_chain(): void
    {
        config(['crm.holidays' => ['2026-09-03', '2026-09-04']]);

        $this->assertSame('Sat 05 Sep 2026, 09:00', $this->clamp('2026-09-03 11:00'));
    }

    public function test_a_holiday_landing_on_a_closed_weekday_chains_past_both(): void
    {
        config([
            'crm.working_days' => [1, 2, 3, 4, 5],
            'crm.holidays'     => ['2026-09-07'],       // the Monday
        ]);

        $this->assertSame('Tue 08 Sep 2026, 09:00', $this->clamp('2026-09-05 23:00'));
    }

    /* ---------------- the config is the only place hours live ---------------- */

    public function test_the_hours_come_from_config(): void
    {
        config(['crm.working_hours' => ['start' => 10, 'end' => 16]]);

        $this->assertSame('Wed 02 Sep 2026, 10:00', $this->clamp('2026-09-02 08:00'));
        $this->assertSame('Thu 03 Sep 2026, 10:00', $this->clamp('2026-09-02 16:00'));
        $this->assertSame('Wed 02 Sep 2026, 15:59', $this->clamp('2026-09-02 15:59'));
    }

    /** Never loop, never throw: hand the time back and let the to-do exist. */
    public function test_an_office_that_is_never_open_returns_the_time_unchanged(): void
    {
        config(['crm.working_days' => []]);

        $this->assertSame('Sat 05 Sep 2026, 23:00', $this->clamp('2026-09-05 23:00'));
    }

    public function test_the_clamp_does_not_mutate_its_argument(): void
    {
        $original = Carbon::parse('2026-09-02 22:00');
        $this->scheduler->withinWorkingHours($original);

        $this->assertSame('2026-09-02 22:00', $original->format('Y-m-d H:i'));
    }

    /* ---------------- the intervals, end to end ---------------- */

    /** Same-day retries stay same-day. The easiest thing to over-correct. */
    public function test_a_four_hour_retry_at_ten_am_stays_at_two_pm_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00'));

        $lead = new Lead(['stage' => 'not_connected', 'not_connected_count' => 0]);

        $this->assertSame(
            'Wed 02 Sep 2026, 14:00',
            $this->scheduler->next($lead, 'not_connected')->format('D d M Y, H:i')
        );
    }

    public function test_a_four_hour_retry_at_five_pm_becomes_tomorrow_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 17:00'));

        $lead = new Lead(['stage' => 'not_connected', 'not_connected_count' => 0]);

        $this->assertSame(
            'Thu 03 Sep 2026, 09:00',
            $this->scheduler->next($lead, 'not_connected')->format('D d M Y, H:i')
        );
    }

    public function test_a_twenty_four_hour_follow_up_from_six_pm_lands_the_next_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 18:00'));

        $lead = new Lead(['stage' => 'site_visit_done', 'not_connected_count' => 0]);

        // 18:00 + 24h = 18:00 tomorrow, which is already shut
        $this->assertSame(
            'Fri 04 Sep 2026, 09:00',
            $this->scheduler->next($lead, 'site_visit_done')->format('D d M Y, H:i')
        );
    }

    public function test_a_forty_eight_hour_follow_up_from_friday_evening_in_a_mon_to_fri_office(): void
    {
        config(['crm.working_days' => [1, 2, 3, 4, 5]]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 17:00'));   // a Friday

        $lead = new Lead(['stage' => 'connected', 'not_connected_count' => 0]);

        // 17:00 Friday + 48h = 17:00 Sunday, a closed day -> Monday opening
        $this->assertSame(
            'Mon 07 Sep 2026, 09:00',
            $this->scheduler->next($lead, 'connected')->format('D d M Y, H:i')
        );
    }

    public function test_the_retry_ladder_and_auto_lost_are_untouched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00'));

        foreach ([0 => 4, 1 => 24, 2 => 48, 3 => 96] as $count => $hours) {
            $lead = new Lead(['stage' => 'not_connected', 'not_connected_count' => $count]);

            $this->assertFalse($this->scheduler->attemptsExhausted($lead, 'not_connected'));
            $this->assertNotNull($this->scheduler->next($lead, 'not_connected'));
        }

        $spent = new Lead(['stage' => 'not_connected', 'not_connected_count' => 4]);

        $this->assertTrue($this->scheduler->attemptsExhausted($spent, 'not_connected'));
        $this->assertNull($this->scheduler->next($spent, 'not_connected'));
    }

    public function test_a_terminal_stage_still_schedules_nothing(): void
    {
        $lead = new Lead(['stage' => 'fresh', 'not_connected_count' => 0]);

        $this->assertNull($this->scheduler->next($lead, 'booking_done'));
        $this->assertNull($this->scheduler->next($lead, 'lost'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
