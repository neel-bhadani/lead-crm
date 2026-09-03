<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Carbon;

/**
 * The date-range filter shared by the Leads and To-do pages.
 *
 * Both offer the same five options — All time, Today, Last 7 days, Last 30
 * days, and a custom pair — over different columns, so the rules about what a
 * range *is* live here and the choice of column stays with the page. Two copies
 * of this arithmetic would be two things that have to agree; one copy is a
 * thing that cannot disagree with itself.
 *
 * `range` and the `from`/`to` pair are alternatives. Only the pair is a real
 * stored filter with two values; "custom" is the word a control needs for the
 * state it is in, and it is derived on the way out rather than stored, where it
 * could fall out of step with the dates it describes.
 */
trait ResolvesDateRange
{
    /**
     * The selected window, or [null, null] for all time.
     *
     * startOfDay() to endOfDay() in IST, both ends inclusive, every time. A
     * bare subDays(7) keeps the current clock time and silently drops the
     * earliest day's morning, which is why "Last 7 days" counts today as one
     * of the seven rather than reaching back seven whole days on top of it.
     *
     * A custom pair outranks a preset — sanitiseDates() has already made sure
     * only one of the two can be in the state, and that a surviving pair makes
     * sense.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function dateWindow(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        if ($from !== null && $to !== null) {
            return [
                Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $to)->endOfDay(),
            ];
        }

        $today = today();   // IST — the app timezone is Asia/Kolkata

        return match ($filters['range'] ?? null) {
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            '7'     => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            '30'    => [$today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()],
            default => [null, null],
        };
    }

    /**
     * What a validator cannot say about the custom pair.
     *
     * It is all or nothing, it does not run backwards, and it does not end in
     * the future. A pair that breaks any of those is dropped here rather than
     * at read time, so a rejected range does not sit in the session being
     * rejected again on every visit.
     *
     * A surviving pair takes `range` out with it. Otherwise a stale "Last 7
     * days" would sit behind a custom range, waiting to reappear the moment the
     * dates were cleared.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function sanitiseDates(array $state): array
    {
        $from = $state['from'] ?? null;
        $to   = $state['to'] ?? null;

        if ($from === null || $to === null) {
            unset($state['from'], $state['to']);

            return $state;
        }

        unset($state['range']);

        $start = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        $end   = Carbon::createFromFormat('Y-m-d', $to)->endOfDay();

        if ($end->lt($start) || $end->gt(today()->endOfDay())) {
            unset($state['from'], $state['to']);
        }

        return $state;
    }

    /**
     * The filters as the page's date control needs to read them: whatever is
     * stored, plus the word for the state it is in. `+` leaves a real preset
     * alone and fills in 'custom' or '' for everything else.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function withRangeWord(array $filters): array
    {
        return $filters + ['range' => isset($filters['from']) ? 'custom' : ''];
    }

    /** The rules the two pages share for the three date keys. */
    protected function dateRangeRules(): array
    {
        return [
            'range' => ['sometimes', 'string', 'in:today,7,30'],
            'from'  => ['sometimes', 'string', 'date_format:Y-m-d'],
            'to'    => ['sometimes', 'string', 'date_format:Y-m-d'],
        ];
    }
}
