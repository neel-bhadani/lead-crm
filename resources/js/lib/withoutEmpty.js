/**
 * '', null and undefined are absence, not values, and absence is not worth
 * putting in a query string. 0 and '0' are values.
 *
 * The server drops empty filter keys too, so sending them changes nothing —
 * this is about the link a user can see, copy and share. A drill-through built
 * from six possible filters of which two are set should read as those two.
 */
export function withoutEmpty(params) {
    return Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    )
}
