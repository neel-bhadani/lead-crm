import { chromium } from 'playwright'
const BASE = 'http://127.0.0.1:8899'
const browser = await chromium.launch({ channel: 'chrome' })
const ctx = await browser.newContext({ viewport: { width: 375, height: 1400 } })
const page = await ctx.newPage()
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' })
await page.fill('input[autocomplete=username]', 'admin@crm.test')
await page.fill('input[autocomplete=current-password]', '123456789')
await page.press('input[autocomplete=current-password]', 'Enter')
await page.waitForURL('**/dashboard'); await page.waitForTimeout(2000)
await page.keyboard.press('Escape'); await page.waitForTimeout(500)
await page.goto(BASE + '/leads', { waitUntil: 'networkidle' })
await page.waitForTimeout(1200)
const b = page.locator('button:has-text("Add lead")')
console.log('count', await b.count())
for (let i = 0; i < await b.count(); i++) {
  const el = b.nth(i)
  console.log(i, 'visible', await el.isVisible(), 'box', JSON.stringify(await el.boundingBox()))
}
console.log('toaster', await page.locator('[class*=fixed]').count())
try { await b.first().click({ timeout: 4000 }) } catch (e) { console.log('CLICK ERR:', String(e).split('\n').slice(0,8).join(' | ')) }
await browser.close()
