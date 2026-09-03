<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import StageBadge from '@/Components/StageBadge.vue'

/*
 | The sign-in notice: what is owed today, said once.
 |
 | It is a panel, not a modal. Nothing here traps focus, covers the page or has
 | to be closed before the dashboard can be used — someone who signed in to look
 | at one number should be able to look at it. Dismissing is a courtesy, not a
 | toll.
 |
 | The server decides whether this renders at all: it sends `todayDigest` only
 | on the first dashboard load of a session that actually has work owed, and
 | never again after. So there is no empty state in here, and no "already seen"
 | check either.
 */
const props = defineProps({ digest: { type: Object, required: true } })

defineEmits(['dismiss'])

// same test the panels below use — only an admin is looking at other people's
// rows, and only they get the per-person breakdown
const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

const total = computed(() => props.digest.total)

const noun = n => (n === 1 ? 'follow-up' : 'follow-ups')

/*
 | "You have 7 follow-ups today" for someone reading their own list; for an
 | admin the list is the whole team's, so the second person would be wrong and
 | the count is stated plainly instead.
 */
const heading = computed(() => isAdmin.value
  ? `${total.value} ${noun(total.value)} today`
  : `You have ${total.value} ${noun(total.value)} today`)

/** "Priya has 3, Amit has 4". Empty, and so hidden, for everyone but an admin. */
const breakdown = computed(() =>
  props.digest.groups.map(g => `${g.name} has ${g.count}`).join(', '))

const more = computed(() => total.value - props.digest.rows.length)

/*
 | An overdue row is from another day, so the day is half of what makes it
 | readable — a bare "10:30 AM" on a task from last Tuesday says the wrong
 | thing. Today's rows get the clock alone. Same split the two panels make.
 */
const clock = v => new Date(v).toLocaleTimeString('en-IN',
  { hour: '2-digit', minute: '2-digit', hour12: true })

const stamp = v => new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true })

const when = row => row.at ? (row.overdue ? stamp(row.at) : clock(row.at)) : '—'
</script>

<template>
  <section
    class="mb-5 overflow-hidden rounded-xl border border-amber-200 bg-amber-50/60"
    role="status"
    aria-label="Follow-ups due today"
  >
    <div class="flex items-start justify-between gap-3 border-b border-amber-200/70 px-5 py-3.5">
      <div class="min-w-0">
        <h2 class="text-sm font-semibold text-amber-900">{{ heading }}</h2>

        <!-- the wording the count is actually counting: today's, plus anything
             still open from before it -->
        <p class="mt-0.5 text-xs text-amber-800/80">
          Due today or earlier, still open.<span v-if="breakdown"> {{ breakdown }}.</span>
        </p>
      </div>

      <button
        type="button"
        class="-mr-1 -mt-1 shrink-0 rounded-md px-2 py-1 text-lg leading-none text-amber-700
               transition hover:bg-amber-100 hover:text-amber-900"
        aria-label="Dismiss"
        @click="$emit('dismiss')"
      >&times;</button>
    </div>

    <!-- the first few, in the order they are due: the most neglected first -->
    <ul class="divide-y divide-amber-200/60">
      <li
        v-for="row in digest.rows" :key="row.id"
        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 px-5 py-2.5"
      >
        <div class="truncate text-sm font-semibold text-slate-800">{{ row.name ?? 'Lead deleted' }}</div>

        <div class="whitespace-nowrap text-right text-xs tabular-nums"
             :class="row.overdue ? 'font-semibold text-rose-700' : 'text-slate-500'">
          {{ when(row) }}
        </div>

        <div class="col-span-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-slate-500">
          <span class="shrink-0 tabular-nums">{{ row.mobile ?? '—' }}</span>
          <StageBadge v-if="row.stage" :stage="row.stage" />
          <!-- who it belongs to only matters when it might not be you -->
          <span v-if="isAdmin && row.owner" class="truncate text-slate-400">{{ row.owner }}</span>
        </div>
      </li>
    </ul>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-amber-200/70 px-5 py-3">
      <p class="text-xs text-amber-800/80">
        <span v-if="more > 0">Showing the first {{ digest.rows.length }} of {{ total }}.</span>
        <span v-else>That is all of them.</span>
      </p>

      <Link :href="route('todos.index', { tab: 'today' })" class="btn px-3 py-1.5 text-xs">
        Open To-do page
      </Link>
    </div>
  </section>
</template>
