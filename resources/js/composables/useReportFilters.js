import { computed } from 'vue'
import { useFilterVisit } from './useFilterVisit.js'

/**
 * The filter state both report pages run on.
 *
 * A report page has up to three axes and each has its own control: the grouping
 * is the sidebar link, the status — on the follow-ups report — is a tab row,
 * and the window is the date picker. None of them may disturb the other two,
 * which is the whole job here: every visit sends `reset=1`, so the request is
 * the whole instruction and anything left out is switched off. Changing tab
 * without resending the range would silently put the report back on the default
 * thirty days.
 *
 * So each action names what it changes and carries everything it does not.
 *
 * @param  {string}   url      the report route
 * @param  {Function} incoming () => the server's resolved `filters` prop
 * @param  {string[]} own      the keys this page owns besides the dates
 */
export function useReportFilters(url, incoming, own) {
    const { visit } = useFilterVisit(url)

    /** The grouping, and on the follow-ups report the status. */
    const kept = computed(() => Object.fromEntries(
        own.map(k => [k, incoming()[k]]).filter(([, v]) => v !== undefined && v !== null),
    ))

    /*
     | The window as the server is holding it. A preset and a custom pair are
     | alternatives — the server reads a pair as custom whatever else it is told
     | — so exactly one of the two shapes goes back, never both.
     */
    const dates = computed(() => {
        const f = incoming()

        return f.from && f.to ? { from: f.from, to: f.to } : { range: f.range }
    })

    /**
     * A new window, from DateRangePicker: { range: key } or { from, to }.
     *
     * The current dates are deliberately NOT spread in first — the choice
     * replaces the window rather than adding to it, and carrying the old pair
     * alongside a new preset would leave the server reading the pair and
     * ignoring the click.
     */
    const selectRange = choice => visit({ reset: 1, ...kept.value, ...choice })

    /** A new value on one of the page's own axes — the status tabs. */
    const set = changes => visit({ reset: 1, ...kept.value, ...dates.value, ...changes })

    /*
     | Clear puts the window back to the default and leaves the report you are
     | looking at alone. The grouping and the status are what the page IS, not
     | filters on it — clearing them would navigate somewhere, which is not what
     | a Clear button beside a date control offers to do.
     */
    const clear = () => visit({ reset: 1, ...kept.value })

    return { selectRange, set, clear }
}
