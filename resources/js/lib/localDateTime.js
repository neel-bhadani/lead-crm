/**
 * `datetime-local` speaks one format and one only: `YYYY-MM-DDTHH:mm`, with no
 * zone on it. `toISOString()` cannot produce it — that returns UTC, so a 9 AM
 * follow-up typed in Kolkata comes back as 03:30 the same day and the input
 * either shows the wrong time or refuses the value outright.
 *
 * Both helpers below therefore read the local clock field by field. The app
 * runs in Asia/Kolkata and so do the people using it, so "local" is the
 * timezone the server will parse the value in.
 */

const pad = n => String(n).padStart(2, '0')

/** A Date (or anything Date accepts) as the string the input wants. */
export function toLocalInput(value) {
    const d = value instanceof Date ? value : new Date(value)

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
        + `T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/**
 * Now, as a `min` for a follow-up input.
 *
 * The server has the last word — the rule is `after:now` and it is checked
 * against the clock at the moment of saving — so this is only to stop the
 * picker offering a date that is already gone. A modal left open past the
 * minute it was rendered still submits, and still gets the real answer back.
 */
export function localNow() {
    return toLocalInput(new Date())
}

/**
 * Is this input value already gone?
 *
 * Empty is not past — an untouched field is the `required` rule's problem, not
 * this one's, and reporting both at once on a form the user has not filled in
 * yet is noise. Anything the browser cannot parse is not past either: a half
 * typed date is still being typed.
 */
export function isPast(value) {
    if (!value) return false

    const t = new Date(value).getTime()

    return Number.isFinite(t) && t <= Date.now()
}

/**
 * What a follow-up field starts at when there is nothing sensible to echo:
 * this time tomorrow.
 *
 * Used by the add-to-do form, and by the reschedule form when the task it is
 * opening is overdue — the one case where the value already on the record
 * cannot be offered back, because the form would then reject its own prefill.
 */
export function defaultFollowUp() {
    return toLocalInput(new Date(Date.now() + 864e5))
}

export default localNow
