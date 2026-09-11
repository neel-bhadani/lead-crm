/* Opens every modal at four widths and measures the controls inside.
   Temporary; not committed. */
import { chromium } from 'playwright'

const BASE = 'http://127.0.0.1:8899'
const WIDTHS = [1440, 1024, 768, 375]

const probe = () => {
  const doc = document.documentElement
  const panel = document.querySelector('[role=dialog]') || document.body

  const spill = []
  const controls = []
  const sel = 'input:not([type=checkbox]):not([type=radio]):not([type=hidden]), select, textarea, button'

  for (const el of panel.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect()
    if (!r.width && !r.height) continue
    const cs = getComputedStyle(el)
    const p = el.parentElement.getBoundingClientRect()
    const name = (el.getAttribute('aria-label') || el.name ||
      el.closest('label')?.querySelector('span')?.textContent ||
      el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 26)

    if (r.right > p.right + 1 || r.left < p.left - 1) {
      spill.push({ tag: el.tagName.toLowerCase(), type: el.type || '', name,
        w: Math.round(r.width), parentW: Math.round(p.width),
        over: Math.round(Math.max(r.right - p.right, p.left - r.left)) })
    }

    controls.push({ tag: el.tagName.toLowerCase(), type: el.type || '', name,
      h: Math.round(r.height), w: Math.round(r.width),
      radius: cs.borderTopLeftRadius, pad: `${cs.paddingTop} ${cs.paddingRight} ${cs.paddingBottom} ${cs.paddingLeft}`,
      minW: cs.minWidth, scrollW: el.scrollWidth, clientW: el.clientWidth })
  }

  const pr = panel.getBoundingClientRect()

  return {
    pageOverflow: doc.scrollWidth - doc.clientWidth,
    panelOverflow: panel.scrollWidth - panel.clientWidth,
    panelW: Math.round(pr.width),
    spill, controls,
  }
}

const browser = await chromium.launch({ channel: 'chrome' })
const out = {}

for (const width of WIDTHS) {
  const ctx = await browser.newContext({ viewport: { width, height: 1400 } })
  const page = await ctx.newPage()
  const settle = (ms = 800) => page.waitForTimeout(ms)

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' })
  await page.fill('input[autocomplete=username]', 'admin@crm.test')
  await page.fill('input[autocomplete=current-password]', '123456789')
  await page.press('input[autocomplete=current-password]', 'Enter')
  await page.waitForURL('**/dashboard')
  await settle(1800)
  await page.keyboard.press('Escape')
  await settle(400)

  const grab = async (label) => { out[`${label} @${width}`] = await page.evaluate(probe) }

  const open = async (url, trigger, label, extra) => {
    await page.goto(BASE + url, { waitUntil: 'networkidle' })
    await settle(900)
    await page.keyboard.press('Escape')
    await settle(300)
    try {
      await page.locator(trigger + " >> visible=true").first().click({ timeout: 8000 })
      await settle(900)
      if (extra) await extra(page)
      await settle(600)
      await grab(label)
    } catch (e) {
      out[`${label} @${width}`] = { error: String(e).split('\n')[0] }
    }
  }

  // Add lead — with broker source (partner picker + inline form) and lost stage (reason)
  await open('/leads', 'button:has-text("Add lead")', 'Add lead', async p => {
    await p.locator('[role=dialog] select').nth(1).selectOption('broker').catch(() => {})
  })
  await open('/leads', 'button:has-text("Add lead")', 'Add lead (lost + partner form)', async p => {
    await p.locator('[role=dialog] select').nth(1).selectOption('broker').catch(() => {})
    await p.waitForTimeout(400)
    await p.locator('button:has-text("+ New") >> visible=true').first().click().catch(() => {})
    await p.waitForTimeout(400)
    const stage = p.locator('[role=dialog] select').last()
    await stage.selectOption('lost').catch(() => {})
  })

  await open('/todos', 'button:has-text("Update")', 'Log call')
  await open('/todos', 'button:has-text("Add follow-up")', 'Add follow-up')
  await open('/users', 'button:has-text("Add user")', 'User modal')
  await open('/projects', 'button:has-text("Add project")', 'Project modal')
  await open('/channel-partners', 'button:has-text("Edit")', 'Channel partner modal')
  await open('/automation', 'button:has-text("New rule")', 'Rule modal')
  await open('/automation?tab=templates', 'button:has-text("New message")', 'Template modal')
  await open('/integrations', 'button:has-text("Configure")', 'Facebook modal')
  await open('/dashboard', 'button:has-text("Custom")', 'Date range popover')
  await open('/reports/leads', 'button:has-text("Custom")', 'Report date popover')

  await ctx.close()
}

await browser.close()
console.log(JSON.stringify(out))
