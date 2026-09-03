/**
 * Filters travel to the server and then get out of the address bar.
 *
 * Every filter control is still a GET visit carrying its parameters, exactly as
 * before — that is what the controller reads. What changes is afterwards: once
 * the visit lands, the query string is lifted back out of the address, so the
 * bar reads `/leads` rather than `/leads?search=meera&stage=connected`.
 *
 * Two rules this follows, both there to avoid breaking something that has
 * nothing to do with filters:
 *
 *   - `history.replaceState` is handed Inertia's own state object back. That
 *     object is how Inertia rebuilds a page on back and forward; replacing it
 *     with null would leave a history entry it cannot restore.
 *   - a visit that finished somewhere else is not ours to rewrite, so the
 *     caller names the path it expects to be sitting on.
 *
 * @param  {?string}  expectedPath  only rewrite while the bar is on this path
 * @return {boolean}                whether the address was actually rewritten
 */
export function cleanUrl(expectedPath = null) {
    const current = new URL(window.location.href)

    if (expectedPath !== null && current.pathname !== expectedPath) return false

    // nothing to strip: leave the entry alone rather than writing it again
    if (!current.search) return false

    window.history.replaceState(
        window.history.state,
        '',
        current.pathname + current.hash,
    )

    return true
}

export default cleanUrl
