/**
 * "2 hours ago", "yesterday", "in 3 days" — for a timestamp the server sent
 * as ISO 8601 with its offset.
 *
 * The difference between two instants does not depend on anybody's timezone,
 * so this is right whatever clock the browser is set to. The exact time, which
 * does depend on it, comes from the server already written out in IST.
 */

const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

const UNITS = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
]

export function relativeTime(value, now = Date.now()) {
    const seconds = Math.round((new Date(value).getTime() - now) / 1000)

    if (Math.abs(seconds) < 45) {
        return 'just now'
    }

    const [unit, size] = UNITS.find(([, size]) => Math.abs(seconds) >= size) ?? UNITS[UNITS.length - 1]

    return format.format(Math.round(seconds / size), unit)
}
