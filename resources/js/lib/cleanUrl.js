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
 * `keep` is the exception, and the dashboard is the page that needs it. Its
 * date range is a filter like any other and lives in the session; its
 * cross-filters are a VIEW — a stage, a source, the stage a lead has reached —
 * and a view is something a person sends to somebody else. So those three keys
 * stay in the address and everything else still comes out of it, which is what
 * makes a filtered dashboard survive a refresh and paste into a message.
 *
 * @param  {?string}   expectedPath  only rewrite while the bar is on this path
 * @param  {string[]}  keep          query keys to leave in the address
 * @return {boolean}                 whether the address was actually rewritten
 */
export function cleanUrl(expectedPath = null, keep = []) {
    const current = new URL(window.location.href)

    if (expectedPath !== null && current.pathname !== expectedPath) return false

    // nothing to strip: leave the entry alone rather than writing it again
    if (!current.search) return false

    const kept = new URLSearchParams()

    for (const key of keep) {
        const value = current.searchParams.get(key)

        // '' is a key on its way out — the filter visits send an empty value to
        // turn one off — so it is absence, not a value worth keeping
        if (value !== null && value !== '') kept.set(key, value)
    }

    const search = kept.toString()

    // the address already reads exactly like this: writing the same entry again
    // would push a duplicate onto the history stack for nothing
    if (search === current.search.slice(1)) return false

    window.history.replaceState(
        window.history.state,
        '',
        current.pathname + (search ? `?${search}` : '') + current.hash,
    )

    return true
}

export default cleanUrl
