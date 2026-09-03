const out = []
const log = (k, v) => out.push(k + ' = ' + (typeof v === 'object' ? JSON.stringify(v) : v))
const wait = ms => new Promise(r => setTimeout(r, ms))
const rect = el => { const r = el.getBoundingClientRect(); return { top: +r.top.toFixed(1), left: +r.left.toFixed(1), bottom: +r.bottom.toFixed(1), h: +r.height.toFixed(1) } }
const inside = (el, anc) => { while (el) { if (el === anc) return true; el = el.parentElement } return false }
const hitName = (x, y) => {
  const el = document.elementFromPoint(x, y)
  if (!el) return 'none'
  if (inside(el, document.querySelector('header'))) return 'HEADER'
  for (const [name, sel] of [['drawer-scrim', 'div.z-30.bg-slate-900\\/45'], ['sidebar', 'aside'], ['modal', '.z-\\[60\\]'], ['picker', '#picker'], ['mobile-actions-bar', 'div.sm\\:hidden']]) {
    for (const n of document.querySelectorAll(sel)) if (inside(el, n)) return name
  }
  if (inside(el, document.querySelector('main'))) return 'main-content'
  return el.tagName + '.' + String(el.className).slice(0, 30)
}
const headerX = () => Math.round(document.querySelector('header').getBoundingClientRect().left + 60)

;(async () => {
  // CSS transitions do not advance under Chrome's virtual time, which freezes
  // transitioned properties at their start value. Kill them so every computed
  // value below is the end state.
  const st = document.createElement('style')
  st.textContent = '*,*::before,*::after{transition-duration:0s!important;animation-duration:0s!important}'
  document.head.appendChild(st)
  await wait(200)
  const header = document.querySelector('header')
  const cs = getComputedStyle(header)
  const mobileBar = document.querySelector('div.sm\\:hidden')

  log('viewport', { w: innerWidth, h: innerHeight })
  log('header.position', cs.position)
  log('header.zIndex', cs.zIndex)
  log('header.background', cs.backgroundColor)
  log('html.scrollPaddingTop', getComputedStyle(document.documentElement).scrollPaddingTop)

  // --- resting at the top of the page ---
  log('atTop.header', rect(header))
  log('atTop.header.boxShadow', cs.boxShadow === 'none' ? 'none' : 'present')
  log('atTop.header.borderBottomColor', cs.borderBottomColor)
  log('atTop.mobileActionsBar', mobileBar ? Object.assign({ display: getComputedStyle(mobileBar).display }, rect(mobileBar)) : 'absent')
  log('atTop.headerActions.display', getComputedStyle(header.querySelector('div.hidden')).display)

  // --- scrolled: header pinned, shadow on, mobile action row gone ---
  let ev = 0
  window.addEventListener('scroll', () => ev++, { passive: true })
  window.scrollTo(0, 900)
  for (let i = 0; i < 80 && !header.className.includes('shadow-sm'); i++) await wait(50)
  log('scrolled.scrollEventsSeen', ev)
  if (!header.className.includes('shadow-sm')) {
    window.dispatchEvent(new Event('scroll')); await wait(100)
    log('scrolled.afterManualEvent.hasShadowClass', header.className.includes('shadow-sm'))
  }
  log('scrolled.scrollY', window.scrollY)
  log('scrolled.header', rect(header))
  log('scrolled.STUCK_HEIGHT', rect(header).bottom)
  log('scrolled.header.boxShadow', getComputedStyle(header).boxShadow === 'none' ? 'none' : 'present')
  log('scrolled.header.hasShadowClass', header.className.includes('shadow-sm'))
  log('scrolled.header.borderBottomColor', getComputedStyle(header).borderBottomColor)
  log('scrolled.mobileActionsBar', mobileBar ? rect(mobileBar) : 'absent')
  log('scrolled.hitInHeaderBand', hitName(headerX(), 20))
  log('scrolled.hit@(w-40,20)', hitName(innerWidth - 40, 20))
  const thead = document.querySelector('#thead-row')
  log('scrolled.theadPosition', thead ? getComputedStyle(thead.parentElement).position : 'absent')
  const panel = document.querySelector('#panel')
  log('scrolled.panel.overscroll', panel ? getComputedStyle(panel).overscrollBehaviorY : 'absent')
  if (panel) {
    const before = window.scrollY
    panel.scrollTop = 400; await wait(120)
    log('panel.scrolledInternally', panel.scrollTop > 0)
    log('panel.pageMoved', window.scrollY !== before)
    log('panel.headerStillPinned', rect(header).top)
  }

  // --- dashboard custom-range popover ---
  if (window.__pickerOpen) {
    window.__pickerOpen.value = true; await wait(250)
    const all = [...document.querySelectorAll('#picker')]
    const p = all.find(el => el.getBoundingClientRect().height > 0) || all[0]
    log('picker.copiesInDom', all.length)
    const vis = p && p.getBoundingClientRect().height > 0
    log('picker.visible', !!vis)
    if (vis) { const r = p.getBoundingClientRect(); log('picker.rect', rect(p)); log('picker.hit', hitName(r.left + 10, r.top + 10)) }
    window.__pickerOpen.value = false; await wait(150)
  }

  // --- mobile drawer (before the modal, so nothing is left over it) ---
  const burger = header.querySelector('button[aria-label="Open menu"]')
  log('burger.visible', getComputedStyle(burger).display !== 'none')
  const br = burger.getBoundingClientRect()
  log('burger.rect', rect(burger))
  log('burger.hitAtOwnPosition', br.height ? hitName(br.left + 10, br.top + 10) : 'hidden(lg)')
  burger.click(); await wait(700)
  const aside = document.querySelector('aside')
  log('drawer.left', +aside.getBoundingClientRect().left.toFixed(1))
  log('drawer.hitInsideDrawer', hitName(aside.getBoundingClientRect().left + 30, 20))
  log('drawer.hitOverHeader', hitName(innerWidth - 40, 20))
  log('drawer.headerRect', rect(header))
  document.querySelector('div.z-30.bg-slate-900\\/45').click(); await wait(700)
  log('afterDrawer.hit@(w-40,20)', hitName(innerWidth - 40, 20))

  // --- modal must cover the header completely ---
  window.__modalOpen.value = true; await wait(900)
  log('modal.hitInHeaderBand', hitName(headerX(), 20))
  log('modal.hit@(w-40,20)', hitName(innerWidth - 40, 20))
  log('modal.hit@(centre)', hitName(Math.round(innerWidth / 2), Math.round(innerHeight / 2)))
  log('modal.headerRect', rect(header))
  window.__modalOpen.value = false; await wait(900)
  log('afterModal.hitInHeaderBand', hitName(headerX(), 20))

  // --- anchor / scrollIntoView must clear the header ---
  window.scrollTo(0, 0); await wait(200)
  document.querySelector('#anchor-target').scrollIntoView(); await wait(300)
  log('anchor.targetTop', rect(document.querySelector('#anchor-target')).top)
  log('anchor.headerBottom', rect(header).bottom)
  log('anchor.clearsHeader', rect(document.querySelector('#anchor-target')).top >= rect(header).bottom)

  document.querySelector('#results').textContent = '\n@@' + out.join('\n@@') + '\n'
  document.title = 'DONE'
})()
