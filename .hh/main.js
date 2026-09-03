import { createApp, h, ref } from 'vue'
import AppLayout from '../resources/js/Layouts/AppLayout.vue'
import Modal from '../resources/js/Components/Modal.vue'

globalThis.route = name => (name === undefined ? { current: () => false } : '#')

const variant = new URLSearchParams(location.search).get('p') || 'leads'
const pickerOpen = ref(false)
const modalOpen = ref(false)
window.__pickerOpen = pickerOpen
window.__modalOpen = modalOpen

// the dashboard range strip + custom-range popover, transcribed from Pages/Dashboard.vue
const dashActions = () => h('div', { class: 'relative w-full sm:w-auto' }, [
  h('div', { class: 'flex w-full overflow-hidden rounded-lg border border-slate-200 bg-white sm:w-auto' },
    ['Today', '7 days', '30 days', '90 days', 'Custom'].map(l =>
      h('button', { class: 'flex-1 whitespace-nowrap border-r border-slate-200 px-2.5 py-2 text-xs text-slate-500 sm:px-3 sm:text-sm' }, l))),
  pickerOpen.value ? h('div', { class: 'fixed inset-0 z-30 bg-slate-900/40 sm:bg-transparent', id: 'picker-scrim' }) : null,
  pickerOpen.value ? h('div', {
    id: 'picker',
    class: 'fixed inset-x-3 bottom-3 z-40 rounded-xl border border-slate-200 bg-white p-4 shadow-xl sm:absolute sm:inset-x-auto sm:bottom-auto sm:right-0 sm:top-full sm:mt-2 sm:w-72',
  }, 'picker') : null,
])

const cfg = {
  dashboard: { title: 'Dashboard', subtitle: 'Overview of leads and follow-ups', actions: dashActions },
  leads:     { title: 'Leads', subtitle: '312 leads', actions: () => h('button', { class: 'btn w-full sm:w-auto' }, 'Add lead') },
  todos:     { title: 'To-do', subtitle: 'Follow-ups due', actions: () => h('button', { class: 'btn w-full sm:w-auto' }, 'Add to-do') },
}[variant]

// a tall page, plus the desktop table from the Leads/To-do pages
const body = () => [
  variant === 'dashboard'
    ? h('div', { class: 'card mb-5 p-4' }, [
        h('div', { id: 'panel', class: 'h-[22rem] overflow-y-auto overscroll-contain' },
          Array.from({ length: 40 }, (_, i) => h('div', { class: 'border-b border-slate-100 p-3' }, `follow-up row ${i}`)))])
    : h('div', { class: 'card mb-5 overflow-hidden' }, [
        h('table', { class: 'hidden w-full text-sm lg:table' }, [
          h('thead', {}, h('tr', { class: 'bg-slate-50 text-left text-xs text-slate-500', id: 'thead-row' },
            ['Name', 'Mobile', 'Stage'].map(t => h('th', { class: 'px-4 py-2.5 font-semibold' }, t)))),
          h('tbody', {}, Array.from({ length: 60 }, (_, i) =>
            h('tr', { class: 'border-t border-slate-100' }, ['Name ' + i, '90000 00000', 'New'].map(c => h('td', { class: 'px-4 py-3' }, c)))))])]),
  h('div', { id: 'anchor-target', class: 'card p-4' }, 'anchor target'),
  ...Array.from({ length: 60 }, (_, i) => h('div', { class: 'card mb-3 p-4' }, `content block ${i}`)),
]

createApp({
  render: () => h(AppLayout, { title: cfg.title, subtitle: cfg.subtitle }, {
    actions: cfg.actions,
    default: () => [
      ...body(),
      h(Modal, { show: modalOpen.value, title: 'A modal', onClose: () => (modalOpen.value = false) },
        { default: () => 'modal body' }),
    ],
  }),
}).mount('#app')
