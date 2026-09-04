<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChartCard from '@/Components/ChartCard.vue'
import StageBadge from '@/Components/StageBadge.vue'
import CompleteTaskModal from '@/Components/CompleteTaskModal.vue'
import CallButtons from '@/Components/CallButtons.vue'
import FollowUpModal from '@/Components/FollowUpModal.vue'
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
 | rebuild all three on every crossing; this waits for the drag to settle.
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
  /*
   | The two tiles the date picker does not move, and the sub-line under each
   | is where that is said. Every other figure on this page is "in the selected
   | period"; these two are "right now", and a reader who is not told cannot
   | tell a deliberate exception from a filter that failed to apply.
   */
  { v: props.cards.today, l: 'Enquiries today', d: 'Since midnight, whatever the dates' },
  { v: props.cards.visits, l: 'Site visits', d: 'Visited in the selected period' },
  { v: props.cards.booked, l: 'Bookings', tone: 'good', d: 'Booked in the selected period' },
  { v: props.cards.lost, l: 'Lost', d: 'Closed without booking in the selected period' },
  // the two panels below this card, added together
  { v: props.cards.pending, l: 'Calls pending', tone: props.cards.pending ? 'bad' : null,
    d: 'Due today or earlier, whatever the dates' },
])

const tick = { color: '#64748b', font: { size: 11 } }
const grid = { color: '#eef2f3' }

/*
 | The two stage charts. Same nine stages in the same order, same colours out
 | of config('crm.stage_colors') — a stage is the colour it is everywhere in
 | the app, badges included — and the same server query, one with a date window
 | and one without; see DashboardController::stagesByLead().
 |
 | They are deliberately not the same shape. The census has the top row to
 | itself and stays horizontal; the period chart shares the row underneath and
 | is vertical. Drawn as a matched pair they read as one chart drawn twice, and
 | the second gets taken for a redrawing of the first rather than for the
 | different question it is: "where does everything stand" against "what came
 | in during these dates". The forms differ so the questions do.
 */

/*
 | The width of the census's label column, as a floor rather than a measurement.
 |
 | Chart.js sizes a category axis to whatever its longest tick happens to need,
 | so left alone the x the bars begin at is a function of the text beside them —
 | it moves with the font the browser resolves, and it is not a number this file
 | knows. A floor pins the nine stages into one left column and the nine bars
 | onto one starting edge.
 |
 | Math.max, not a bare assignment: a floor can only ever add room, so no label
 | can be squeezed into a column too narrow to hold it. On a phone the floor is
 | lower, because there the column is competing with the bars for a third of the
 | width rather than a tenth.
 */
const STAGE_LABEL_COL = 148
const STAGE_LABEL_COL_NARROW = 116

/*
 | Every lead, at the stage it stands at now. The date picker cannot move it.
 |
 | Horizontal, and now across the full width of the page: a bar has the whole
 | row to run along, so it can afford to be a little thicker than it was when
 | this shared a row with the chart below it.
 */
const allStagesChart = computed(() => {
  const rows = props.charts.stagesAllTime.bars

  return {
    type: 'bar',
    data: {
      labels: rows.map(r => r.label),
      datasets: [{
        data: rows.map(r => r.value),
        backgroundColor: rows.map(r => r.color),
        borderRadius: 4, barThickness: narrow.value ? 14 : 20,
      }],
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid, ticks: { ...tick, precision: 0 }, beginAtZero: true },
        y: {
          grid: { display: false },
          ticks: tick,
          afterFit: scale => {
            scale.width = Math.max(
              scale.width,
              narrow.value ? STAGE_LABEL_COL_NARROW : STAGE_LABEL_COL,
            )
          },
        },
      },
    },
  }
})

/*
 | The same census, narrowed to the leads created inside the range. Its bars
 | sum to the New enquiries card.
 |
 | Vertical, at half the width, with nine stage names to fit along the bottom.
 | Laid flat the longest of them is wider than the slot it gets — about 110px
 | of text in something between 30 and 55 — so they are laid at a fixed angle
 | instead. Rotation is the one answer here that does not depend on how long
 | the words happen to be: the spacing a rotated label needs is set by its line
 | height and the angle, not by its length, so nine of them clear each other at
 | every width this card is ever given, and the axis simply grows downwards for
 | the longest one rather than clipping it.
 |
 | Abbreviating them was the alternative and is worse: a stage's name is
 | config('crm.stages'), the same string the badges and the filters show, and
 | shortening it here would put a second vocabulary for the nine stages in this
 | file for one axis to use.
 */
const periodStagesChart = computed(() => {
  const rows = props.charts.stagesInPeriod.bars

  return {
    type: 'bar',
    data: {
      labels: rows.map(r => r.label),
      datasets: [{
        data: rows.map(r => r.value),
        backgroundColor: rows.map(r => r.color),
        borderRadius: 4, maxBarThickness: narrow.value ? 22 : 30,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: {
          grid: { display: false },
          ticks: {
            ...tick,
            // a shade smaller than the rest of the page's ticks, which is what
            // buys the angled labels their room back
            font: { size: 10 },
            /*
             | Both pinned, and that is the point of setting them.
             |
             | autoSkip drops every other label the moment the axis is short of
             | room, and a stage missing from the axis reads as a stage with
             | nothing in it — the exact thing zero-filling the bars is there to
             | prevent. And left to choose an angle, Chart.js straightens the
             | labels whenever it decides they fit and lays them back down when
             | they do not, so the axis would change shape as the range changed
             | the numbers beside it.
             */
            autoSkip: false, minRotation: 45, maxRotation: 45,
          },
        },
        y: { grid, ticks: { ...tick, precision: 0 }, beginAtZero: true },
      },
    },
  }
})

/*
 | The note under "Where all enquiries stand", and it carries the count.
 |
 | The count is the point of putting a note there at all: it is the one number
 | on the card that says how big the book is, and it does not move when the
 | picker does. A reader who changes the range and watches this stay put has
 | been told, without reading a word, that this chart is not part of the range.
 */
const allStagesNote = computed(() =>
  `All enquiries ever received · ${props.charts.stagesAllTime.total} total`)

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
 | Those are not the same question and the window cannot answer it, and it can
 | answer it even less now this card is half a row from lg up rather than a
 | whole one below 1280. A 1024-wide window leaves it 356px and a 1440-wide one
 | 564px, so at both of those the legend belongs underneath — but a 1900-wide
 | monitor gives the same half-row card 714px, where it belongs beside. No
 | window breakpoint can express that. A ResizeObserver on a wrapper around the
 | card measures the thing that actually decides, at every width, with no grid
 | arithmetic to keep in step — which is why moving the grid to two columns at
 | lg needed nothing changed here.
 |
 | 600px is the line: below it the plot is too narrow to give a quarter of
 | itself away to a column of labels, so they go underneath; above it there is
 | room for both side by side.
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

/* ---------------- the sign-in modal ---------------- */

/*
 | Opened a beat after the dashboard is on screen, never before.
 |
 | A modal that is already up when the page paints covers a blank page: the
 | reader is asked to dismiss something before they have seen what it is in
 | front of. So this waits for a frame to be handed to the compositor, and then
 | a further moment on top, and the dashboard is what appears first.
 |
 | requestAnimationFrame alone is not the promise it looks like — the callback
 | runs *before* the paint it is scheduled with. Pairing it with a timeout is
 | what puts this after a real frame rather than merely after mount.
 */
const DIGEST_DELAY_MS = 450

const digestOpen = ref(false)

let digestFrame
let digestTimer

onMounted(() => {
  // the server sends this at most once a session, and only when there is
  // something owed, so its presence is the whole decision
  if (!props.todayDigest) return

  digestFrame = requestAnimationFrame(() => {
    digestTimer = setTimeout(() => { digestOpen.value = true }, DIGEST_DELAY_MS)
  })
})

onBeforeUnmount(() => {
  cancelAnimationFrame(digestFrame)
  clearTimeout(digestTimer)
})

/*
 | Closing is local and needs to be nothing more. The server recorded that this
 | session was told at the moment it sent the prop, so the next dashboard visit
 | will not send `todayDigest` at all — there is no state here to persist and no
 | request to make. Escape, the close button, the overlay and "Go to my to-do
 | list" all land here.
 */
const closeDigest = () => { digestOpen.value = false }

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
   | move them. It can move all three: logging a call usually moves a lead, and
   | a lead that moves is a lead standing somewhere else — in both stage charts
   | at once. Refreshing only the cards left the Booking card reading 3 beside a
   | chart still drawing 2 — the numbers disagreeing on screen while the
   | database was perfectly consistent.
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
      Three charts: the standing census across the top, and under it the two
      questions that are only about this period.

      The top row is where the reading starts, so it gets the full width and
      the horizontal bars that suit it. The row underneath holds the two
      charts a date range does move — the same stage census narrowed to these
      dates, and where those enquiries came from — side by side from lg up and
      stacked below it, which is the width at which half a row stops being
      enough for either of them.

      One grid rather than two, with the census spanning both columns, so a
      single gap-4 still sets the column gap and the row gap and the three
      cards cannot drift out of step with each other.

      The titles carry the distinction between the two stage charts, and the
      notes say it a second time — "All enquiries ever received" against
      "Leads created in the selected period". The census ignores the picker and
      its note is where that is said; it used to carry an ALL TIME chip beside
      its title instead, which made it read as a different kind of panel rather
      than as one of the charts.

      So there is nothing here but a title, a note and a config on every one of
      the three. Every scrap of card styling is in ChartCard and takes no
      argument, which is what makes the headers identical and keeps the plot
      boxes in step across the bottom row.
    -->
    <div class="mb-5 grid gap-4 lg:grid-cols-2">
      <!--
        Every lead ever, by the stage each is at now. No date filter.

        Wrapped for the same reason the doughnut is: ChartCard's root is the
        grid item and its classes take no argument, so the column span belongs
        to a wrapper. `grid` on it, not just `min-w-0`, so the lone child
        stretches to the cell in both axes instead of sitting at its natural
        height inside a stretched wrapper.
      -->
      <div class="grid min-w-0 lg:col-span-2">
        <ChartCard title="Where all enquiries stand"
                   :note="allStagesNote"
                   :config="allStagesChart" />
      </div>

      <!-- the same census, narrowed to the leads created in the range -->
      <ChartCard title="Enquiries in this period"
                 note="Leads created in the selected period"
                 :config="periodStagesChart" />

      <!--
        Wrapped, so the legend can follow the width of the card rather than the
        width of the window. The wrapper is the grid item and the card fills it,
        so the measurement costs ChartCard nothing — no prop, no emit, no
        knowledge on its side that anyone is watching.

        `grid` on the wrapper, not just `min-w-0`: a lone grid child stretches
        to its cell in both axes, so this card is sized by the row exactly as
        the unwrapped one beside it is. Left as a plain block it would sit at
        its natural height inside a stretched wrapper — invisible while both
        cards in the row are the same height, and a bug the day one of them is
        not.
      -->
      <div ref="sourceCard" class="grid min-w-0">
        <ChartCard title="Where enquiries came from" :config="sourceChart"
                   :empty="!charts.bySource.length" empty-text="No leads in this range" />
      </div>
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

    <!--
      The sign-in modal. Teleported to the body by Modal.vue, so where it sits
      in this template decides nothing but reading order; it lives beside the
      page's other modal rather than up among the cards it opens over.
    -->
    <FollowUpModal :show="digestOpen" :digest="todayDigest" @close="closeDigest" />
  </AppLayout>
</template>
