<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StageBadge from '@/Components/StageBadge.vue'
import CompleteTaskModal from '@/Components/CompleteTaskModal.vue'
import TodoFormModal from '@/Components/TodoFormModal.vue'
import CallButtons from '@/Components/CallButtons.vue'
import AssignedTo from '@/Components/AssignedTo.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'

const props = defineProps({ todos: Object, tab: String, counts: Object, filters: Object, options: Object })

const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

/*
 | The keys are the filter vocabulary and are unchanged — the session, the
 | controller's validator and the dashboard's link all speak `overdue`. The
 | labels are what a user reads, and "Waiting longer" is what the dashboard
 | panel that links here is called, so arriving from it does not land on a tab
 | with a different name for the same list.
 */
const tabs = [
  { key: 'overdue', label: 'Waiting longer' },
  { key: 'today', label: 'Today' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'completed', label: 'Completed' },
]

const f = reactive({
  search: props.filters.search ?? '',
  type: props.filters.type ?? '',
  assigned_to: props.filters.assigned_to ?? '',
})

// the tab we last asked for: props.tab only catches up once the visit lands,
// so a debounced search firing mid-flight would otherwise send the old tab
let wantedTab = props.tab
watch(() => props.tab, v => (wantedTab = v))

const { visit } = useFilterVisit(route('todos.index'))

/*
 | Every field the page owns goes out on every visit, the tab among them, and
 | reset=1 goes with them: the request is the whole instruction, so what is
 | named is on and what is missing is off. The empty ones are dropped before
 | it leaves — see withoutEmpty() in the composable for why the two belong
 | together.
 */
const push = () => visit({ reset: 1, tab: wantedTab, ...f })

const filters = useDebouncedFilters(f, push)

// a tab click goes out at once — drop any pending search push so it cannot
// land afterwards and pull us back to the previous tab
const setTab = key => { filters.cancel(); wantedTab = key; push() }

const clearFilters = () => {
  filters.silently(() => Object.keys(f).forEach(k => (f[k] = '')))
  filters.cancel()

  // every field is empty now, so this sends reset=1 and the tab: the session
  // entry is wiped, and the tab rides along because it is the one the user is
  // looking at, not something they asked to lose
  push()
}

/* modals */
const completeOpen = ref(false)
const active = ref(null)
const formOpen = ref(false)
const editing = ref(null)

const openComplete = t => { active.value = t; completeOpen.value = true }
const openAdd = () => { editing.value = null; formOpen.value = true }
const openEdit = t => { editing.value = t; formOpen.value = true }

/* helpers */
const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

const relative = v => {
  const h = (new Date(v) - new Date()) / 36e5
  if (h < -24) return `${Math.round(-h / 24)}d late`
  if (h < 0) return `${Math.round(-h)}h late`
  if (h < 24) return `in ${Math.max(1, Math.round(h))}h`
  return `in ${Math.round(h / 24)}d`
}

const isOverdue = t => t.status === 'pending' && new Date(t.scheduled_at) < new Date()

/*
 | The server no longer sends a row whose lead has been deleted — Todo::hasLead()
 | leaves it out. These guards are the second line: a null relation should cost
 | one row's worth of detail, not the whole page.
 */
const leadName = t => t.lead?.full_name ?? 'Lead deleted'
const leadLine = t => [t.lead?.mobile_number, t.lead?.project?.name].filter(Boolean).join(' · ') || '—'
</script>

<template>
  <Head title="To-do" />

  <AppLayout title="To-do (Schedule)" subtitle="Your calls and site visits">
    <template #actions>
      <button class="btn w-full sm:w-auto" @click="openAdd">Add to-do</button>
    </template>

    <div class="card overflow-hidden">

      <!-- tabs -->
      <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-2 sm:px-4">
        <button v-for="t in tabs" :key="t.key"
                class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium"
                :class="tab === t.key
                  ? 'border-teal-700 font-semibold text-slate-900'
                  : 'border-transparent text-slate-500 hover:text-slate-700'"
                @click="setTab(t.key)">
          {{ t.label }}
          <span class="ml-1 text-xs text-slate-400">{{ counts[t.key] }}</span>
        </button>
      </div>

      <!-- filters -->
      <div class="flex flex-wrap gap-2 border-b border-slate-100 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search lead name or mobile" class="w-full sm:!w-64" />
        <select v-model="f.type" class="w-full sm:!w-40">
          <option value="">All types</option>
          <option v-for="(l, k) in options.types" :key="k" :value="k">{{ l }}</option>
        </select>
        <select v-if="isAdmin" v-model="f.assigned_to" class="w-full sm:!w-44"
                aria-label="Assigned to">
          <option value="">Assigned to</option>
          <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.first_name }} {{ u.last_name }}</option>
        </select>
        <button class="btn-ghost w-full sm:w-auto" @click="clearFilters">Clear</button>
      </div>

      <!-- empty -->
      <div v-if="!todos.data.length" class="px-5 py-14 text-center text-sm text-slate-500">
        <p class="mb-1 font-semibold text-slate-700">
          {{ tab === 'overdue' ? 'Nothing waiting'
             : tab === 'today' ? 'No calls due today'
             : tab === 'upcoming' ? 'Nothing scheduled ahead' : 'No completed tasks yet' }}
        </p>
        New tasks appear automatically as calls are logged.
      </div>

      <!-- rows: table on desktop, cards on mobile -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 text-left text-xs text-slate-500">
            <th class="px-4 py-2.5 font-semibold">Lead</th>
            <th class="px-4 py-2.5 font-semibold">Type</th>
            <th class="px-4 py-2.5 font-semibold">Scheduled</th>
            <th class="px-4 py-2.5 font-semibold">Stage</th>
            <th v-if="tab === 'completed'" class="px-4 py-2.5 font-semibold">Outcome</th>
            <th v-if="tab === 'completed'" class="px-4 py-2.5 font-semibold">Remarks</th>
            <th v-else-if="isAdmin" class="px-4 py-2.5 font-semibold">Handled by</th>
            <th v-if="tab === 'completed'" class="px-4 py-2.5 font-semibold">Completed</th>
            <th v-else class="px-4 py-2.5 font-semibold">Due</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in todos.data" :key="t.id"
              class="border-b border-l-4 border-slate-100"
              :class="isOverdue(t) ? 'border-l-rose-600 bg-rose-50/40' : 'border-l-transparent'">
            <td class="px-4 py-3">
              <div class="font-semibold" :class="t.lead ? '' : 'italic text-slate-400'">{{ leadName(t) }}</div>
              <div class="text-xs text-slate-400">{{ leadLine(t) }}</div>
            </td>
            <td class="px-4 py-3">{{ options.types[t.type] }}</td>
            <td class="px-4 py-3">{{ fmt(t.scheduled_at) }}</td>
            <td class="px-4 py-3"><StageBadge v-if="t.lead" :stage="t.lead.stage" /></td>

            <template v-if="tab === 'completed'">
              <td class="px-4 py-3"><StageBadge :stage="t.outcome_stage" /></td>
              <td class="max-w-[260px] px-4 py-3 text-slate-600">{{ t.remarks || '—' }}</td>
              <td class="px-4 py-3">
                {{ fmt(t.completed_at) }}
                <div class="text-xs text-slate-400">{{ t.completer?.display_name ?? '—' }}</div>
              </td>
            </template>
            <template v-else>
              <td v-if="isAdmin" class="px-4 py-3"><AssignedTo :user="t.owner" /></td>
              <td class="px-4 py-3" :class="isOverdue(t) ? 'font-semibold text-rose-700' : 'text-slate-500'">
                {{ relative(t.scheduled_at) }}
              </td>
            </template>

            <td class="px-4 py-3">
              <!-- dialling is useful on a closed task too, so it sits outside the pending check -->
              <div class="flex items-center gap-1.5">
                <template v-if="t.status === 'pending'">
                  <button class="btn px-3 py-1 text-xs" @click="openComplete(t)">Log call</button>
                  <button class="btn-xs" @click="openEdit(t)">Edit</button>
                </template>
                <CallButtons v-if="t.lead?.mobile_number" compact :mobile="t.lead.mobile_number" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="todos.data.length" class="divide-y divide-slate-100 lg:hidden">
        <div v-for="t in todos.data" :key="t.id" class="border-l-4 p-4"
             :class="isOverdue(t) ? 'border-l-rose-600 bg-rose-50/40' : 'border-l-transparent'">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="truncate font-semibold" :class="t.lead ? '' : 'italic text-slate-400'">{{ leadName(t) }}</div>
              <div class="text-xs text-slate-400">{{ t.lead?.mobile_number ?? '—' }}</div>
            </div>
            <StageBadge v-if="t.lead" :stage="t.lead.stage" />
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Type</dt><dd class="text-right">{{ options.types[t.type] }}</dd>
            <dt class="text-slate-400">Scheduled</dt><dd class="text-right">{{ fmt(t.scheduled_at) }}</dd>
            <template v-if="t.status === 'pending'">
              <dt class="text-slate-400">Due</dt>
              <dd class="text-right" :class="isOverdue(t) ? 'font-semibold text-rose-700' : ''">
                {{ relative(t.scheduled_at) }}
              </dd>
            </template>
            <template v-else>
              <dt class="text-slate-400">Outcome</dt>
              <dd class="text-right">{{ options.stages[t.outcome_stage] }}</dd>
            </template>
            <template v-if="isAdmin">
              <dt class="text-slate-400">Handled by</dt>
              <dd class="text-right"><AssignedTo :user="t.owner" /></dd>
            </template>
          </dl>

          <p v-if="t.remarks" class="mt-2 text-xs text-slate-500">{{ t.remarks }}</p>

          <!--
            Full width on a phone: this is the layout where someone is actually
            standing in front of the customer, and the dialler is the point.
          -->
          <div v-if="t.lead?.mobile_number || t.status === 'pending'"
               class="mt-3 space-y-2 border-t border-slate-100 pt-3">
            <CallButtons v-if="t.lead?.mobile_number" :mobile="t.lead.mobile_number" />
            <div v-if="t.status === 'pending'" class="flex gap-2">
              <button class="btn flex-1 py-1.5 text-xs" @click="openComplete(t)">Log call</button>
              <button class="btn-xs flex-1" @click="openEdit(t)">Edit</button>
            </div>
          </div>
        </div>
      </div>

      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
        <span>{{ todos.total }} task{{ todos.total === 1 ? '' : 's' }}</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in todos.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <CompleteTaskModal :show="completeOpen" :todo="active" :options="options" @close="completeOpen = false" />
    <TodoFormModal :show="formOpen" :todo="editing" :options="options" @close="formOpen = false" />
  </AppLayout>
</template>
