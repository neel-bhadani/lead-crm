/*
 | Which stages and sources a dropdown may offer.
 |
 | The server ships two things in `options`: `stages` / `sources`, which are
 | EVERY row so that a badge, a chip or a history line can look a label up
 | whatever state it is in, and `activeStages` / `activeSources`, which are the
 | keys that may still be CHOSEN. Splitting them is what lets a stage be retired
 | without the leads standing in it turning into raw keys on screen.
 |
 | These two helpers are how a <select> uses that split. Nothing here is a
 | guard: LeadRequest and CompleteTodoRequest decide what may be saved, and this
 | only decides what is worth showing.
 */

/**
 * The options a form control may offer: the active ones, plus whichever value
 * the record being edited already holds.
 *
 * That last clause is the whole point. A lead sitting in a stage the admin
 * switched off yesterday must still open, still show its own stage in the
 * dropdown, and still save — the server allows exactly that, and a control that
 * dropped the option would leave the field empty and the first save would move
 * the lead somewhere nobody chose.
 *
 * @param {Record<string,string>} labels   every key => label
 * @param {string[]|undefined}    active   the keys still in use
 * @param {string|null}           current  the value this record already holds
 * @returns {{key: string, label: string, retired: boolean}[]}
 */
export function pickable(labels, active, current) {
  const live = Array.isArray(active) ? active : null

  return Object.entries(labels ?? {})
    // no `active` list at all means an older payload: offer everything rather
    // than nothing, which is how this behaved before stages became editable
    .filter(([key]) => live === null || live.includes(key) || key === current)
    .map(([key, label]) => ({
      key,
      label: live !== null && !live.includes(key) ? `${label} (no longer in use)` : label,
      retired: live !== null && !live.includes(key),
    }))
}

/**
 * The options a FILTER may offer: every key there has ever been, with the
 * retired ones marked.
 *
 * Filters read rather than write. Narrowing a list to a source that was retired
 * last month is a perfectly reasonable thing to want — those leads are still in
 * the database and still on the reports — so hiding the option would hide data
 * rather than protect anything. The server's list filters validate against
 * every key for the same reason.
 *
 * @param {Record<string,string>} labels
 * @param {string[]|undefined}    active
 * @returns {{key: string, label: string, retired: boolean}[]}
 */
export function filterable(labels, active) {
  const live = Array.isArray(active) ? active : null

  return Object.entries(labels ?? {}).map(([key, label]) => ({
    key,
    label: live !== null && !live.includes(key) ? `${label} (no longer in use)` : label,
    retired: live !== null && !live.includes(key),
  }))
}
