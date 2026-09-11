/* Measures every form control on every page and modal, at four widths.
   Temporary; not committed. */
import { chromium } from 'playwright'

const BASE = 'http://127.0.0.1:8899'
const WIDTHS = [1440, 1024, 768, 375]

// [label, url, steps to reach the state we want to measure]
const PAGES = [
  ['Dashboard',        '/dashboard'],
  ['Leads',            '/leads'],
  ['Follow-ups',       '/todos'],
  ['Follow-ups done',  '/todos?tab=completed'],
  ['Reports leads',    '/reports/leads'],
  ['Reports followups','/reports/followups'],
  ['Users',            '/users'],
  ['Projects',         '/projects'],
  ['Channel partners', '/channel-partners'],
  ['Automation',       '/automation'],
  ['Integrations',     '/integrations'],
  ['Alerts',           '/alerts'],
]

const probe = () => {
  const doc = document.documentElement
  const overflow = doc.scrollWidth - doc.clientWidth

  // anything wider than its own parent's content box
  const spill = []
  const controls = []

  const sel = 'input:not([type=checkbox]):not([type=radio]):not([type=hidden]), select, textarea, button, a.btn, a.btn-ghost, a.btn-xs'

  for (const el of document.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect()
    if (!r.width && !r.height) continue          // hidden
    const cs = getComputedStyle(el)
    const p = el.parentElement.getBoundingClientRect()

    if (r.right > p.right + 1 || r.left < p.left - 1) {
      spill.push({
        tag: el.tagName.toLowerCase(), type: el.type || '',
        label: (el.getAttribute('aria-label') || el.name || el.textContent || '').trim().slice(0, 30),
        w: Math.round(r.width), parentW: Math.round(p.width),
        over: Math.round(Math.max(r.right - p.right, p.left - r.left)),
      })
    }

    controls.push({
      tag: el.tagName.toLowerCase(),
      type: el.type || '',
      cls: el.className.toString().slice(0, 60),
      label: (el.getAttribute('aria-label') || el.name || el.textContent || '').trim().slice(0, 26),
      h: Math.round(r.height), w: Math.round(r.width),
      radius: cs.borderTopLeftRadius,
      padY: `${cs.paddingTop}/${cs.paddingBottom}`,
      padR: cs.paddingRight,
      minW: cs.minWidth,
    })
  }

  return { overflow, scrollWidth: doc.scrollWidth, clientWidth: doc.clientWidth, spill, controls }
}

const browser = await chromium.launch({ channel: 'chrome' })
const out = {}

for (const width of WIDTHS) {
  const ctx = await browser.newContext({ viewport: { width, height: 1400 } })
  const page = await ctx.newPage()

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' })
  await page.fill('input[autocomplete=username]', 'admin@crm.test')
  await page.fill('input[autocomplete=current-password]', '123456789')
  await page.press('input[autocomplete=current-password]', 'Enter')
  await page.waitForURL('**/dashboard')
  await page.waitForTimeout(1800)
  await page.keyboard.press('Escape')
  await page.waitForTimeout(500)

  for (const [label, url] of PAGES) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' })
    await page.waitForTimeout(900)
    await page.keyboard.press('Escape')
    await page.waitForTimeout(300)
    out[`${label} @${width}`] = await page.evaluate(probe)
  }

  await ctx.close()
}

await browser.close()
console.log(JSON.stringify(out))
