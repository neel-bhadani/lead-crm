<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChartCard from '@/Components/ChartCard.vue'
import StageBadge from '@/Components/StageBadge.vue'
import CompleteTaskModal from '@/Components/CompleteTaskModal.vue'
import CallButtons from '@/Components/CallButtons.vue'
import FollowUpNotice from '@/Components/FollowUpNotice.vue'
import { useFilterVisit } from '@/composables/useFilterVisit.js'

const props = defineProps({
  range: Object,
  cards: Object,
  charts: Object,
  // null unless this is the first dashboard load of a session that has work
  // owed — see the notice block further down
  todayDigest: { type: Object, default: null },
  followUps: Object,
  options: Object,
})

// the panels name the staff member only for an admin: everyone else is looking
// at their own rows and would read their own name on every one
const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

/* ---------------- date range ---------------- */

const presets = [
  { key: 'today', label: 'Today' },
  { key: '7',     label: 'Last 7 days' },
  { key: '30',    label: 'Last 30 days' },
]

const pickerOpen = ref(false)
const draft = ref({ from: props.range.from, to: props.range.to })
const pickerError = ref('')

const isCustom = computed(() => props.range.key === 'custom')

// once a custom range is applied the button carries it, e.g. "1 Jun – 15 Jun"
const customLabel = computed(() => isCustom.value ? props.range.label : 'Custom')

const { visit, cleanUrl } = useFilterVisit(route('dashboard'))

/*
 | A preset and a custom pair are alternatives, so each visit names only the
 | one being chosen and carries reset=1 with it. The request is then the whole
 | instruction — what is missing is off — so yesterday's custom dates cannot
 | sit in the session outranking the preset the user just clicked. See
 | withoutEmpty() in the composable for why the reset and the dropped empties
 | belong together.
 */
const setRange = key => {
  pickerOpen.value = false
  visit({ reset: 1, range: key })
}

const openPicker = () => {
  draft.value = { from: props.range.from, to: props.range.to }
  pickerError.value = ''
  pickerOpen.value = true
}

const closePicker = () => {
  pickerOpen.value = false
  pickerError.value = ''
}

/*
 | Both dates are ISO yyyy-mm-dd, so they compare correctly as plain strings
 | and none of this has to build a Date. That matters here: the browser may be
 | in any timezone, and `range.today` is today in IST as the server sees it.
 */
const spanDays = (from, to) =>
  Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) + 1

// the same rules the server applies, so an invalid range is never sent
const validate = ({ from, to }) => {
  if (!from || !to) return 'Choose both a From and a To date.'
  if (from > to) return 'From must not be after To.'
  if (to > props.range.today) return 'To must not be in the future.'
  if (spanDays(from, to) > props.range.maxSpanDays) return 'Choose a range of two years or less.'
  return ''
}

const applyCustom = () => {
  pickerError.value = validate(draft.value)

  if (pickerError.value) return

  pickerOpen.value = false
  visit({ reset: 1, from: draft.value.from, to: draft.value.to })
}

// clear a stale complaint as soon as the user starts fixing it
watch(draft, () => { pickerError.value = '' }, { deep: true })

// Escape closes it from anywhere, not only from inside the two date fields
const onKeydown = e => { if (e.key === 'Escape') closePicker() }
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

/*
 | The chart configs below read this to place a legend and size a bar. It used
 | to be a function calling window.innerWidth, which meant the answer was
 | whatever the width happened to be the one time each config was built — and
 | after that it never changed, so a chart dragged from desktop to phone width
 | kept its desktop legend until the page was reloaded.
 |
 | A ref fixes that, but it deliberately holds the *answer* rather than the
 | width: assigning the same boolean is a no-op in Vue, so the configs are
 | rebuilt only when the breakpoint is actually crossed. Holding the raw width
 | would give every config a new identity on every pixel of a drag, and
 | ChartCard would tear down and rebuild each chart for all of them.
 */
const NARROW_BELOW = 860

const narrow = ref(window.innerWidth < NARROW_BELOW)

/*
 | Debounced, because crossing the breakpoint is the one thing here that does
 | rebuild a chart. A drag that wobbles either side of 860 would otherwise
 | rebuild all four on every crossing; this waits for the drag to settle.
 */
let widthTimer
const onWidthChange = () => {
  clearTimeout(widthTimer)
  widthTimer = setTimeout(() => { narrow.value = window.innerWidth < NARROW_BELOW }, 120)
}

onMounted(() => window.addEventListener('resize', onWidthChange))
onBeforeUnmount(() => {
  clearTimeout(widthTimer)
  window.removeEventListener('resize', onWidthChange)
})

/*
 | The six tiles, in the words a builder uses. Every label here is a thing that
 | happened to a customer — an enquiry, a visit, a booking — rather than a
 | column name. The sub-line under each says which population it counted and
 | nothing else; the arithmetic behind them is untouched.
 */
const kpis = computed(() => [
  { v: props.cards.total, l: 'New enquiries', d: 'In the selected period',
    /*
     | Two extra lines, and both belong to the number above them.
     |
     | The delta compares the period immediately before this one. It reads as a
     | bare line now — when there is no prior period it is simply absent, where
     | it used to print "No prior period", a sentence whose only content was
     | that it had nothing to say.
     |
     | Conversion belongs here and nowhere else, because the number above it is
     | its denominator: of these enquiries, this many have booked since. A dash
     | when there were none to divide by: never 0%, never an error.
     */
    d2: props.cards.delta === null
      ? null
      : `${props.cards.delta >= 0 ? '+' : ''}${props.cards.delta}% vs previous`,
    d3: props.cards.conversion === null
      ? null
      : `${props.cards.conversion}% booked so far` },
  { v: props.cards.today, l: 'Enquiries today', d: 'Since midnight' },
  { v: props.cards.visits, l: 'Site visits', d: 'Customers who visited' },
  { v: props.cards.booked, l: 'Bookings', tone: 'good', d: 'Units booked' },
  { v: props.cards.lost, l: 'Lost', d: 'Enquiries closed without booking' },
  // STOCK: the two panels below this card, added together
  { v: props.cards.pending, l: 'Calls pending', tone: props.cards.pending ? 'bad' : null,
    d: 'Due today or earlier' },
])

const tick = { color: '#64748b', font: { size: 11 } }
const grid = { color: '#eef2f3' }

/*
 | Both stage charts, from one factory.
 |
 | They are deliberately identical to look at — same nine stages in the same
 | order, same colours, same horizontal bars — because the point of the pair is
 | that they can be read against each other. What separates them is the words
 | in the header: one is a census, one is a count of events in the range, and
 | the title and note say which.
 */
const stageBars = rows => ({
  type: 'bar',
  data: {
    labels: rows.map(r => r.label),
    datasets: [{
      data: rows.map(r => r.value),
      backgroundColor: rows.map(r => r.color),
      borderRadius: 4, barThickness: narrow.value ? 12 : 15,
    }],
  },
  options: {
    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { grid, ticks: { ...tick, precision: 0 }, beginAtZero: true },
              y: { grid: { display: false }, ticks: tick } },
  },
})

// FLOW: the stages reached inside the range. This is the one that reconciles —
// its Booking done bar is the Bookings card, out of the same query.
const stageChangesChart = computed(() => stageBars(props.charts.stageChanges))

// STOCK: where every lead sits right now, at no date in particular
const pipelineChart = computed(() => stageBars(props.charts.byStage.bars))

/*
 | The note under "Where all enquiries stand", and it carries the count.
 |
 | The count used to sit beside the title with an ALL TIME chip after it, three
 | separate things competing for the same line. In the note it is doing the one
 | job it was ever for: saying which population the bars are drawn from, so
 | nobody lines 56 up against a New enquiries card reading 6 and calls it a
 | contradiction.
 */
const pipelineNote = computed(() =>
  `Every enquiry ever received · ${props.charts.byStage.total} total`)

/*
 | The source palette. The stage charts do not use it — a stage's colour comes
 | from config('crm.stage_colors') so that a stage is the same colour
 | everywhere in the app, badges included.
 */
const PALETTE = ['#2F6FB0', '#0F766E', '#8145A8', '#C2711A', '#5B58B8', '#1E7A45', '#B4881B', '#8A94A0']

/*
 | Where the doughnut's legend goes, decided by the width of the card it is in
 | rather than the width of the window.
 |
 | Those are not the same question and the window cannot answer it. The chart
 | grid is one column up to 1280 and two above it, so a 1024-wide window gives
 | this card 728px and a 1440-wide one gives it 564px — the wider window is the
 | one where a legend down the right-hand side was eating a quarter of the
 | plot. No window breakpoint can express that. A ResizeObserver on a wrapper
 | around the card measures the thing that actually decides, at every width,
 | with no grid arithmetic to keep in step.
 |
 | 600px is the line: below it the chart is sharing its row and the legend goes
 | underneath, above it the card has a row to itself (or the monitor is wide
 | enough that two of them are still roomy) and the legend sits beside.
 |
 | The ref holds the answer, not the width: assigning the same boolean is a
 | no-op in Vue, so the config is rebuilt only when the threshold is crossed
 | and not on every pixel of a drag.
 */
const LEGEND_BESIDE_ABOVE = 600

const sourceCard = ref(null)
const sourceRoomy = ref(true)

let sourceObserver
let sourceTimer

onMounted(() => {
  if (!sourceCard.value) return

  /*
   | Measured once, synchronously, before the observer is attached. A child's
   | onMounted runs before its parent's, so the chart already exists by the
   | time this does; taking the first reading here rather than waiting out the
   | debounce means a narrow card never paints a right-hand legend for a fifth
   | of a second and then throws it away.
   */
  sourceRoomy.value = sourceCard.value.offsetWidth >= LEGEND_BESIDE_ABOVE

  // debounced after that, because crossing the threshold rebuilds the chart
  // and a drag should not rebuild it on every pixel
  sourceObserver = new ResizeObserver(([entry]) => {
    clearTimeout(sourceTimer)
    sourceTimer = setTimeout(() => {
      sourceRoomy.value = entry.contentRect.width >= LEGEND_BESIDE_ABOVE
    }, 120)
  })

  sourceObserver.observe(sourceCard.value)
})

onBeforeUnmount(() => {
  clearTimeout(sourceTimer)
  sourceObserver?.disconnect()
  sourceObserver = null
})

const sourceChart = computed(() => {
  return {
    type: 'doughnut',
    data: {
      labels: props.charts.bySource.map(r => r.label),
      datasets: [{ data: props.charts.bySource.map(r => r.value),
                   backgroundColor: PALETTE, borderWidth: 2, borderColor: '#fff' }],
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '58%',
      plugins: {
        legend: { position: sourceRoomy.value ? 'right' : 'bottom',
                  labels: { boxWidth: 9, boxHeight: 9, padding: 9, color: '#64748b', font: { size: 11.5 } } },
        tooltip: { callbacks: {
          label: c => ` ${c.label}: ${c.raw} (${props.charts.bySource[c.dataIndex]?.percent ?? 0}%)`,
        } },
      },
    },
  }
})

/*
 | STOCK: what is still to be done, by kind of task.
 |
 | Vertical bars, where the two stage charts are horizontal. The form is doing
 | work: this chart sits next to the source doughnut in a row of four, and
 | nothing about it is comparable with the stage bars above it, so it should not
 | look like a third one of them.
 |
 | One colour across all four bars, not a palette. The server orders these
 | biggest-first, so a per-bar palette would repaint a category every time the
 | ranking moved — Call blue this week and orange the next, which invites a
 | reader to think the colour meant something. It is one series of one measure;
 | the bar heights are the whole message.
 |
 | Labels come from the server, which took them from config('crm.todo_types').
 | No task type is spelled out in this file.
 */
const todoTypeChart = computed(() => ({
  type: 'bar',
  data: {
    labels: props.charts.byTodoType.bars.map(r => r.label),
    datasets: [{
      data: props.charts.byTodoType.bars.map(r => r.value),
      backgroundColor: '#0F766E',
      borderRadius: 4, maxBarThickness: narrow.value ? 34 : 52,
    }],
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: tick },
      y: { grid, ticks: { ...tick, precision: 0 }, beginAtZero: true },
    },
  },
}))

// the count, in the note — the same job pipelineNote does for the snapshot
const todoTypeNote = computed(() => {
  const n = props.charts.byTodoType.total
  return `${n} ${n === 1 ? 'task' : 'tasks'} still open`
})

/* ---------------- the sign-in notice ---------------- */

/*
 | Dismissal is local and needs to be nothing more.
 |
 | The server has already recorded that this session was shown the notice, so
 | the next dashboard visit will not send `todayDigest` at all — there is no
 | state here to persist and no request to make. This ref only closes the panel
 | on the page it is already open on.
 */
const noticeDismissed = ref(false)

/* ---------------- follow-up panels ---------------- */

/*
 | Neither panel follows the range selector — they are about right now, so the
 | server sends them unfiltered and this only decides how they read.
 */
const clock = v => v ? new Date(v).toLocaleTimeString('en-IN',
  { hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

/*
 | Both panels are pending work, and LeadController::destroy() cancels a
 | deleted lead's pending todos, so a null lead should never reach them —
 | Todo::hasLead() makes sure of it either way. These guards are the second
 | line: a null relation should cost one row's worth of detail, not the whole
 | dashboard.
 */
const leadName = t => t.lead?.full_name ?? 'Lead deleted'

const leadMobile = t => t.lead?.mobile_number ?? '—'

/*
 | Name · role on one muted line, not the stacked AssignedTo cell the tables
 | use: three lines of it inside a two-line row, repeated down the panel, was
 | most of the noise. The panel is already a list of follow-ups assigned to
 | someone, so the "Assigned to" label is dropped too — a label repeated on
 | every row of a list of assignments says nothing the list has not.
 */
const assignee = t => t.owner
  ? [t.owner.display_name, props.options.roleLabels?.[t.owner.role] ?? t.owner.role]
      .filter(Boolean).join(' · ')
  : '—'

/*
 | The two panels. "Due today" and "Waiting longer" — the second one is
 | everything still open that was scheduled before today, and it is named for
 | what it is rather than for a status word nobody in a sales office uses.
 |
 | The rose wash the late rows carried is gone. A tinted row plus a coloured
 | left border plus rose bold text was three signals for one fact, and the
 | weakest of the three was sitting behind the words. What is left is a thin
 | coloured border down the edge and the timestamp itself in rose: the row
 | reads as text on white, and the eye still finds the late ones.
 */
const panels = computed(() => [
  {
    key: 'today',
    tab: 'today',
    title: 'Due today',
    // amber, so a glance tells the two panels apart without reading the headers
    accent: 'border-l-amber-500',
    timeClass: 'text-slate-500',
    // nothing in this panel is from another day, so the date would add nothing
    time: clock,
    empty: "Nothing due today — you're clear",
    ...props.followUps.today,
  },
  {
    key: 'waiting',
    /*
     | `overdue` survives as an identifier — the Todo scope, the payload key and
     | the To-do page's tab all still call this set that, and one internal word
     | for one concept is worth more than a rename that would have to land in
     | three places to stay honest. Nothing here is rendered; the panel is
     | titled above and the To-do page's own tab is labelled to match.
     */
    tab: 'overdue',
    title: 'Waiting longer',
    accent: 'border-l-rose-500',
    timeClass: 'font-semibold text-rose-700',
    // these are from earlier days, so the day matters as much as the time
    time: fmt,
    empty: 'Nothing waiting — every call has been made on time.',
    ...props.followUps.overdue,
  },
])

/* ---------------- logging a call ---------------- */

const completeOpen = ref(false)
const active = ref(null)

const openComplete = t => { active.value = t; completeOpen.value = true }

/*
 | CompleteTaskModal is shared with the To-do page, so it cannot know which
 | props its host needs refreshing, and it emits `close` on cancel just as it
 | does on save. Watching the request go past is what lets the modal stay
 | untouched: flag the POST on its way out, reload on the way back in.
 |
 | router.reload() re-visits the current URL, which forces preserveScroll and
 | preserveState — the page does not jump and the panels keep their scroll
 | offsets. The range comes back from the session either way; cleanUrl runs
 | after it because Inertia writes that URL to the address bar on the way
 | through, and it is the one URL a filter control did not put there.
 */
const isLogCall = url => /\/todos\/\d+\/complete\/?$/.test(new URL(String(url), window.location.origin).pathname)

let logging = false

const stopBefore = router.on('before', e => {
  const { method, url } = e.detail.visit
  logging = method === 'post' && isLogCall(url)
})

const stopSuccess = router.on('success', () => {
  if (!logging) return
  logging = false

  /*
   | Charts included, and they have to be.
   |
   | They used to be left out, on the grounds that one logged call does not
   | move them. It moves three of the four: "Stage changes in this range" reads
   | the same completed-to-do history the cards do, and logging a call closes a
   | to-do and usually moves a lead, so the pipeline snapshot and the to-do
   | backlog shift with it. Refreshing only the cards left the Booking card
   | reading 3 beside a chart still drawing 2 — the numbers disagreeing on
   | screen while the database was perfectly consistent.
   |
   | `todayDigest` is deliberately not in the list. It is a sign-in notice, not
   | a live count, and re-requesting it would make the server think it had been
   | shown a second time.
   */
  router.reload({ only: ['cards', 'charts', 'followUps'], onFinish: cleanUrl })
})

onBeforeUnmount(() => { stopBefore(); stopSuccess() })
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout title="Dashboard" subtitle="Overview of leads and follow-ups">
    <template #actions>
      <div class="relative w-full sm:w-auto">
        <div class="flex w-full overflow-hidden rounded-lg border border-slate-200 bg-white sm:w-auto">
          <button
            v-for="r in presets" :key="r.key"
            class="flex-1 whitespace-nowrap border-r border-slate-200 px-2.5 py-2 text-xs sm:px-3 sm:text-sm"
            :class="range.key === r.key ? 'bg-slate-900 text-white' : 'text-slate-500'"
            @click="setRange(r.key)"
          >{{ r.label }}</button>

          <button
            class="flex-1 whitespace-nowrap px-2.5 py-2 text-xs sm:px-3 sm:text-sm"
            :class="isCustom ? 'bg-slate-900 text-white' : 'text-slate-500'"
            aria-haspopup="dialog"
            :aria-expanded="pickerOpen"
            @click="pickerOpen ? closePicker() : openPicker()"
          >{{ customLabel }}</button>
        </div>

        <!-- click-away target; also the scrim behind the sheet on a phone -->
        <div v-if="pickerOpen" class="fixed inset-0 z-30 bg-slate-900/40 sm:bg-transparent"
             @click="closePicker" />

        <!--
          A sheet on phones and a popover on wider screens. Either way it is
          taken out of flow, so opening it never pushes the header apart.
        -->
        <div
          v-if="pickerOpen"
          class="fixed inset-x-3 bottom-3 z-40 rounded-xl border border-slate-200 bg-white p-4 shadow-xl
                 sm:absolute sm:inset-x-auto sm:bottom-auto sm:right-0 sm:top-full sm:mt-2 sm:w-72"
          role="dialog" aria-label="Custom date range"
        >
          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">From</span>
              <input v-model="draft.from" type="date" :max="range.today" class="w-full text-sm" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">To</span>
              <input v-model="draft.to" type="date" :min="draft.from" :max="range.today"
                     class="w-full text-sm" />
            </label>
          </div>

          <p v-if="pickerError" class="mt-2.5 text-xs font-medium text-rose-700" role="alert">
            {{ pickerError }}
          </p>

          <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="btn-ghost" @click="closePicker">Cancel</button>
            <button type="button" class="btn" @click="applyCustom">Apply</button>
          </div>
        </div>
      </div>
    </template>

    <!--
      The sign-in notice, above everything else and blocking nothing.

      Rendered only when the server sent it, which it does once per session and
      only when there is something owed — so there is no empty state and no
      "seen" check on this side. Dismissing hides it here; the next visit does
      not send it.
    -->
    <FollowUpNotice v-if="todayDigest && !noticeDismissed" :digest="todayDigest"
                    @dismiss="noticeDismissed = true" />

    <!-- KPI strip -->
    <div class="mb-5 grid grid-cols-1 overflow-hidden rounded-xl border border-slate-200 bg-white
                sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
      <div v-for="(k, i) in kpis" :key="i"
           class="border-b border-slate-100 p-4 last:border-b-0 sm:border-r xl:border-b-0 xl:last:border-r-0">
        <div class="text-2xl font-bold tracking-tight"
             :class="k.tone === 'bad' ? 'text-rose-700' : k.tone === 'good' ? 'text-emerald-700' : ''">
          {{ k.v }}
        </div>
        <div class="mt-0.5 text-xs text-slate-500">{{ k.l }}</div>
        <div class="mt-1.5 text-[11px] text-slate-400">{{ k.d }}</div>
        <!-- only New enquiries carries these, and only when it has them -->
        <div v-if="k.d2" class="text-[11px] text-slate-400">{{ k.d2 }}</div>
        <div v-if="k.d3" class="text-[11px] text-slate-400">{{ k.d3 }}</div>
      </div>
    </div>

    <!--
      Four charts, two by two on desktop and stacked on a phone.

      The rows are the pairing: a stage read two ways on top, and underneath
      the two questions that are not about stages at all. One grid rather than
      two rows of two, so a single gap-4 sets both the column and the row gap.

      The titles carry the distinction, which is the whole point of the pair on
      the top row. "Where all enquiries stand" is a census and "What happened in
      this period" is a count of events; read as "Pipeline right now" and "Stage
      changes in this range" they were two spellings of the same phrase and a
      reader had no reason to expect different numbers. The notes say it a
      second time — "Every enquiry ever received" against "during the selected
      dates" — and that is where it stops. The two that ignore the date picker
      used to carry a tinted header as well, which made them read as a
      different kind of panel rather than as two of four charts.

      So there is nothing here but a title, a note and a config on every one of
      the four. Every scrap of card styling is in ChartCard and takes no
      argument, which is what makes the four headers identical and keeps the
      plot boxes in step across a row.
    -->
    <div class="mb-5 grid gap-4 xl:grid-cols-2">
      <!-- STOCK: where every enquiry sits right now, at no date in particular -->
      <ChartCard title="Where all enquiries stand"
                 :note="pipelineNote"
                 :config="pipelineChart" />

      <!-- FLOW: same events as the three cards, one bar per stage -->
      <ChartCard title="What happened in this period"
                 note="Each stage reached during the selected dates"
                 :config="stageChangesChart" />

      <!--
        Wrapped, so the legend can follow the width of the card rather than the
        width of the window. The wrapper is the grid item and the card fills it,
        so the measurement costs ChartCard nothing — no prop, no emit, no
        knowledge on its side that anyone is watching.

        `grid` on the wrapper, not just `min-w-0`: a lone grid child stretches
        to its cell in both axes, so this card is sized by the row exactly as
        the three unwrapped ones are. Left as a plain block it would sit at its
        natural height inside a stretched wrapper — invisible while all four
        cards are the same height, and a bug the day one of them is not.
      -->
      <div ref="sourceCard" class="grid min-w-0">
        <ChartCard title="Where enquiries came from" :config="sourceChart"
                   :empty="!charts.bySource.length" empty-text="No leads in this range" />
      </div>

      <!-- STOCK again: the backlog, which no date range can move -->
      <ChartCard title="Pending work by type"
                 :note="todoTypeNote"
                 :config="todoTypeChart" />
    </div>

    <!--
      The two work lists, equal weight. Due today is first in the array, so it
      is also the first card once the grid stacks on a phone.
    -->
    <div class="grid gap-4 xl:grid-cols-2">
      <div v-for="p in panels" :key="p.key" class="card overflow-hidden">
        <!-- fixed while the list scrolls under it -->
        <div class="border-b border-slate-100 px-5 py-3.5">
          <h3 class="text-sm font-semibold">
            {{ p.title }}
            <!-- the count reads second: label first, number after -->
            <span class="ml-1.5 text-xs font-normal text-slate-400">{{ p.total }}</span>
          </h3>
        </div>

        <!--
          A fixed height either way, so the two panels line up whether one holds
          fifty rows and the other none, and a phone does not get an endless
          page. overscroll-contain stops a flick past the last row from carrying
          on into the page behind it.
        -->
        <div v-if="!p.rows.length"
             class="flex h-[22rem] items-center justify-center px-5 text-center text-sm
                    font-semibold text-slate-700">
          {{ p.empty }}
        </div>

        <div v-else class="h-[22rem] overflow-y-auto overscroll-contain">
          <div class="divide-y divide-slate-100">
            <!--
              Two lines, and the same shape on every one: the name bold at the
              top left, the time at the top right, the detail underneath, the
              actions under the time. The eye runs down four columns instead of
              hunting across a row of content-sized boxes.

              The right-hand column is a fixed 11rem from sm up, not `auto`.
              That is the fix for the thing the rows were actually doing wrong:
              each row is its own grid, so an `auto` column was as wide as
              whatever that row happened to hold — a long timestamp here, a
              lead with no mobile and therefore no call buttons there — and the
              time and the buttons landed at a different x on every line. A
              fixed track makes the column a property of the panel rather than
              of the row. 11rem is the button cluster (two 36px icon links, a
              6px gap each side and a ~73px Log call) with room to spare, so a
              font that renders a little wide cannot push it out of the track.
            -->
            <div v-for="t in p.rows" :key="t.id"
                 class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2
                        border-l px-4 py-3 transition-colors hover:bg-slate-50/70
                        sm:grid-cols-[minmax(0,1fr)_11rem] sm:gap-y-1.5 sm:px-5"
                 :class="p.accent">

              <!-- line 1: name, and the time hard against the right edge -->
              <div class="truncate text-sm font-semibold"
                   :class="t.lead ? '' : 'italic text-slate-400'">{{ leadName(t) }}</div>

              <div class="whitespace-nowrap text-right text-xs tabular-nums" :class="p.timeClass">
                {{ p.time(t.scheduled_at) }}
              </div>

              <!--
                line 2. Fixed widths on the first two, so the mobile starts at
                the same x on every row and the badge does too, whatever the
                stage is called. The mobile is never truncated — half a phone
                number is useless — and the badge box is 10.25rem because that
                is the widest configured stage label, "Site visit scheduled",
                with its dot and pill padding, plus a little slack. Sized to
                this row's badge instead, the column would move down the panel.

                The assignee takes what is left, and `basis-32` is what makes it
                degrade by wrapping instead of by shrinking: where the row is
                too narrow to seat all three it drops to its own line at full
                width, rather than being squeezed to "P…". flex-wrap, not a
                three-track grid, for exactly that reason.
              -->
              <div class="col-span-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1
                          text-xs text-slate-500 sm:col-span-1">
                <span class="w-20 shrink-0 tabular-nums">{{ leadMobile(t) }}</span>

                <span class="w-[10.25rem] shrink-0">
                  <StageBadge v-if="t.lead" :stage="t.lead.stage" />
                </span>

                <!-- admin only, and for everyone else the element is not there
                     at all, so it cannot leave a gap where it would have sat -->
                <span v-if="isAdmin" class="min-w-0 flex-1 basis-32 truncate text-slate-400"
                      :title="assignee(t)">{{ assignee(t) }}</span>
              </div>

              <!--
                One filled button per row, and it is the one that does something
                to the data. Call and WhatsApp are the icon-only outline variant
                CallButtons already ships for the To-do table — no fork, no
                second style — and the arbitrary variant only evens their height
                up with Log call so the three read as one control group.
              -->
              <div class="col-span-2 flex items-center justify-end gap-1.5 sm:col-span-1">
                <CallButtons v-if="t.lead?.mobile_number" compact
                             class="[&>a]:py-1.5" :mobile="t.lead.mobile_number" />

                <button type="button"
                        class="btn whitespace-nowrap px-2.5 py-1.5 text-xs"
                        @click="openComplete(t)">Log call</button>
              </div>
            </div>
          </div>

          <p v-if="p.total > p.rows.length"
             class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400">
            Showing first {{ p.rows.length }} of {{ p.total }} —
            <Link :href="route('todos.index', { tab: p.tab })" class="underline hover:text-teal-700">
              open the To-do page to see all.</Link>
          </p>
        </div>
      </div>
    </div>

    <!-- the same modal the To-do page uses, and the same endpoint behind it -->
    <CompleteTaskModal :show="completeOpen" :todo="active" :options="options"
                       @close="completeOpen = false" />
  </AppLayout>
</template>
