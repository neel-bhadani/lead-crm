/*
 | A few things about the filter plumbing that are awkward to check by hand:
 | what leaves the address bar, what stays in `history.state`, what actually
 | goes on the wire, and that the debounce fires once per change.
 |
 | Run with: node filtercheck.mjs
 */
import { reactive, nextTick } from 'vue'

// useFilterVisit registers onMounted; called outside a component Vue only warns
const warn = console.warn
console.warn = m => { if (!String(m).includes('onMounted is called')) warn(m) }

const calls = []
const setLocation = href => {
  const u = new URL(href, 'http://localhost')
  globalThis.window.location = { href: u.href, origin: u.origin, pathname: u.pathname, search: u.search, hash: u.hash }
}
// enough of a browser for @inertiajs/core to finish importing
globalThis.window = {
  navigator: { userAgent: 'node' },
  addEventListener() {},
  removeEventListener() {},
  history: {
    state: { INERTIA_PAGE_KEY: 'kept' },
    replaceState(state, title, url) { calls.push({ state, url }); setLocation(url) },
  },
}
globalThis.document = {
  addEventListener() {},
  removeEventListener() {},
  querySelector: () => null,
  documentElement: { scrollTop: 0, scrollLeft: 0 },
}

setLocation('/leads')

const { useFilterVisit, useDebouncedFilters } = await import('./resources/js/composables/useFilterVisit.js')
const { router } = await import('@inertiajs/vue3')

let failures = 0
const check = (label, ok, detail = '') => {
  if (!ok) failures++
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? `  ->  ${detail}` : ''}`)
}

/* ---------------- the address bar ---------------- */

const cases = [
  ['/leads?search=meera&stage=connected',    '/leads',     '/leads'],
  ['/leads?page=3&search=meera',             '/leads',     '/leads'],
  ['/leads?page=3',                          '/leads',     '/leads'],
  ['/leads?stage=connected#top',             '/leads',     '/leads#top'],
  ['/leads',                                 '/leads',     null],
  ['/todos?tab=overdue',                     '/todos',     '/todos'],
  ['/todos?reset=1&tab=today',               '/todos',     '/todos'],
  ['/dashboard?reset=1&from=2026-01-01&to=2026-02-01', '/dashboard', '/dashboard'],
  // a visit that finished somewhere else is not ours to rewrite
  ['/login?redirect=1',                      '/leads',     null],
]

for (const [href, base, expected] of cases) {
  calls.length = 0
  setLocation(href)
  const { cleanUrl } = useFilterVisit(base)
  cleanUrl()
  const got = calls.length ? calls.at(-1).url : null
  const stateKept = calls.length ? calls.at(-1).state?.INERTIA_PAGE_KEY === 'kept' : true
  check(href, got === expected && stateKept,
    `${got === null ? '(untouched)' : got}${stateKept ? '' : '  [STATE LOST]'}`)
}

/* ---------------- what goes on the wire ---------------- */

const sent = []
router.get = (url, params, options) => sent.push({ url, params, options })

setLocation('/leads')
const { visit } = useFilterVisit('/leads')

visit({ reset: 1, search: 'meera', stage: '', project_id: null, source: undefined, assigned_to: 2 })
check('empty values are dropped, real ones kept',
  JSON.stringify(sent.at(-1).params) === JSON.stringify({ reset: 1, search: 'meera', assigned_to: 2 }),
  new URLSearchParams(sent.at(-1).params).toString())

check('0 survives — it is a value, not absence',
  (visit({ reset: 1, assigned_to: 0 }), sent.at(-1).params.assigned_to === 0))

visit({ reset: 1, search: '', stage: '' })
check('Clear sends reset and nothing else',
  JSON.stringify(sent.at(-1).params) === JSON.stringify({ reset: 1 }),
  new URLSearchParams(sent.at(-1).params).toString())

const o = sent.at(-1).options
check('preserveState, preserveScroll and replace are untouched',
  o.preserveState === true && o.preserveScroll === true && o.replace === true)

let ranAfter = false
visit({ reset: 1 }, { onFinish: () => { ranAfter = true } })
calls.length = 0
setLocation('/leads?reset=1')
sent.at(-1).options.onFinish()
check("a caller's own onFinish still runs, after the strip",
  ranAfter && calls.at(-1)?.url === '/leads')

/* ---------------- the debounce ---------------- */

const f = reactive({ search: 'meera', stage: 'connected' })
let pushes = 0
const d = useDebouncedFilters(f, () => pushes++, 5)
const settle = async () => { await nextTick(); await new Promise(r => setTimeout(r, 25)) }
const count = (label, want) => check(`${label} (pushes=${pushes}, want ${want})`, pushes === want)

f.search = 'rahul';                                    await settle(); count('a change pushes once', 1)
d.silently(() => { f.search = ''; f.stage = '' }); d.cancel(); await settle(); count('Clear does not wake the watcher', 1)
f.stage = 'lost';                                      await settle(); count('the next real change still pushes', 2)
d.silently(() => { f.stage = 'lost' });                await nextTick()
f.stage = 'fresh';                                     await settle(); count('a no-op silently() does not swallow the next change', 3)

process.exit(failures ? 1 : 0)
