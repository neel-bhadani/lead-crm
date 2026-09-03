<script setup>
import { ref, reactive, computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StageBadge from '@/Components/StageBadge.vue'
import LeadFormModal from '@/Components/LeadFormModal.vue'
import LeadViewModal from '@/Components/LeadViewModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import AssignedTo from '@/Components/AssignedTo.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'

const props = defineProps({ leads: Object, filters: Object, options: Object })

const role = computed(() => usePage().props.auth.user.role)
const canEdit = computed(() => role.value !== 'telecaller')
const isAdmin = computed(() => role.value === 'admin')

/* ---------------- filters, kept in the session ---------------- */

const f = reactive({
  search: props.filters.search ?? '',
  stage: props.filters.stage ?? '',
  project_id: props.filters.project_id ?? '',
  source: props.filters.source ?? '',
  assigned_to: props.filters.assigned_to ?? '',

  // no control on the page — a hand-typed range is carried back out so the
  // reset below does not silently drop it on the next keystroke
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
})

const { visit } = useFilterVisit(route('leads.index'))

/*
 | Every field the page owns goes out on every visit, and reset=1 goes with
 | them, so the request is the whole instruction: what is named is on, what is
 | missing is off. The empty ones are dropped before it leaves — see
 | withoutEmpty() in the composable for why the two belong together.
 */
const push = () => visit({ reset: 1, ...f })

const filters = useDebouncedFilters(f, push)

const clearFilters = () => {
  filters.silently(() => Object.keys(f).forEach(k => (f[k] = '')))
  filters.cancel()

  // every field is empty now, so this sends reset=1 and nothing else: the
  // session entry is wiped and nothing survives to reappear on the next visit
  push()
}

/* ---------------- modals ---------------- */
const formOpen = ref(false)
const editing = ref(null)
const viewOpen = ref(false)
const viewId = ref(null)
const confirmOpen = ref(false)
const deleting = ref(null)
const processing = ref(false)

const openAdd = () => { editing.value = null; formOpen.value = true }
const openEdit = lead => { editing.value = lead; formOpen.value = true }
const openView = id => { viewId.value = id; viewOpen.value = true }

const editFromView = id => {
  viewOpen.value = false
  editing.value = props.leads.data.find(l => l.id === id)
  formOpen.value = true
}

const confirmDelete = lead => { deleting.value = lead; confirmOpen.value = true }

const doDelete = () => {
  processing.value = true
  router.delete(route('leads.destroy', deleting.value.id), {
    preserveScroll: true,
    onFinish: () => { processing.value = false; confirmOpen.value = false },
  })
}

/* ---------------- helpers ---------------- */
const fmtDate = v => v ? new Date(v).toLocaleDateString('en-IN',
  { day: '2-digit', month: 'short', year: '2-digit' }) : '—'

const ageClass = d => d === null ? 'text-slate-400'
  : d > 7 ? 'text-rose-700' : d > 3 ? 'text-amber-700' : 'text-slate-600'
</script>

<template>
  <Head title="Leads" />

  <AppLayout title="Leads" subtitle="All enquiries across projects">
    <template #actions>
      <button v-if="canEdit" class="btn w-full sm:w-auto" @click="openAdd">Add lead</button>
    </template>

    <div class="card overflow-hidden">

      <!-- filters -->
      <div class="flex flex-wrap gap-2 border-b border-slate-100 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search name, mobile or email"
               class="w-full sm:!w-64" />
        <select v-model="f.stage" class="w-full sm:!w-40">
          <option value="">All stages</option>
          <option v-for="(l, k) in options.stages" :key="k" :value="k">{{ l }}</option>
        </select>
        <select v-model="f.project_id" class="w-full sm:!w-44">
          <option value="">All projects</option>
          <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="f.source" class="w-full sm:!w-40">
          <option value="">All sources</option>
          <option v-for="(l, k) in options.sources" :key="k" :value="k">{{ l }}</option>
        </select>
        <select v-if="isAdmin" v-model="f.assigned_to" class="w-full sm:!w-44"
                aria-label="Assigned to">
          <option value="">Assigned to</option>
          <option v-for="u in options.users" :key="u.id" :value="u.id">
            {{ u.first_name }} {{ u.last_name }}
          </option>
        </select>
        <button class="btn-ghost w-full sm:w-auto" @click="clearFilters">Clear</button>
      </div>

      <!-- empty -->
      <div v-if="!leads.data.length" class="px-5 py-14 text-center text-sm text-slate-500">
        <p class="mb-1 font-semibold text-slate-700">No leads match these filters</p>
        Clear the filters, or add the first lead for this project.
      </div>

      <!-- desktop table -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 text-left text-xs text-slate-500">
            <th class="px-4 py-2.5 font-semibold">Name</th>
            <th class="px-4 py-2.5 font-semibold">Mobile</th>
            <th class="px-4 py-2.5 font-semibold">Stage</th>
            <th class="px-4 py-2.5 font-semibold">Project</th>
            <th class="px-4 py-2.5 font-semibold">Source</th>
            <th v-if="isAdmin" class="px-4 py-2.5 font-semibold">Assigned to</th>
            <th class="px-4 py-2.5 font-semibold">Created</th>
            <th class="px-4 py-2.5 font-semibold">Days in stage</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in leads.data" :key="l.id"
              class="border-b border-l-4 border-slate-100 hover:bg-slate-50/70"
              :style="{ borderLeftColor: options.stageColors[l.stage] }">
            <td class="px-4 py-3">
              <div class="font-semibold">{{ l.full_name }}</div>
              <div class="text-xs text-slate-400">{{ l.email }}</div>
            </td>
            <td class="px-4 py-3">{{ l.mobile_number }}</td>
            <td class="px-4 py-3"><StageBadge :stage="l.stage" /></td>
            <td class="px-4 py-3">{{ l.project?.name }}</td>
            <td class="px-4 py-3">
              {{ options.sources[l.source] }}
              <div v-if="l.broker_name" class="text-xs text-slate-400">{{ l.broker_name }}</div>
            </td>
            <td v-if="isAdmin" class="px-4 py-3"><AssignedTo :user="l.owner" /></td>
            <td class="px-4 py-3">{{ fmtDate(l.created_at) }}</td>
            <td class="px-4 py-3 font-semibold" :class="ageClass(l.days_in_stage)">
              {{ l.days_in_stage === null ? '—' : l.days_in_stage + 'd' }}
            </td>
            <td class="px-4 py-3">
              <div class="flex gap-1.5">
                <button class="btn-xs" @click="openView(l.id)">View</button>
                <button v-if="canEdit" class="btn-xs" @click="openEdit(l)">Edit</button>
                <button v-if="isAdmin" class="btn-xs hover:!border-rose-600 hover:!text-rose-700"
                        @click="confirmDelete(l)">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- mobile cards: a nine column table is unusable on a phone -->
      <div v-if="leads.data.length" class="divide-y divide-slate-100 lg:hidden">
        <div v-for="l in leads.data" :key="l.id" class="border-l-4 p-4"
             :style="{ borderLeftColor: options.stageColors[l.stage] }">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="truncate font-semibold">{{ l.full_name }}</div>
              <div class="text-xs text-slate-400">{{ l.mobile_number }}</div>
            </div>
            <StageBadge :stage="l.stage" />
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Project</dt><dd class="text-right">{{ l.project?.name }}</dd>
            <dt class="text-slate-400">Source</dt><dd class="text-right">{{ options.sources[l.source] }}</dd>
            <template v-if="isAdmin">
              <dt class="text-slate-400">Assigned to</dt>
              <dd class="text-right"><AssignedTo :user="l.owner" /></dd>
            </template>
            <dt class="text-slate-400">Days in stage</dt>
            <dd class="text-right font-semibold" :class="ageClass(l.days_in_stage)">
              {{ l.days_in_stage === null ? '—' : l.days_in_stage + 'd' }}
            </dd>
          </dl>

          <div class="mt-3 flex gap-2 border-t border-slate-100 pt-3">
            <button class="btn-xs flex-1" @click="openView(l.id)">View</button>
            <button v-if="canEdit" class="btn-xs flex-1" @click="openEdit(l)">Edit</button>
            <button v-if="isAdmin" class="btn-xs flex-1 hover:!border-rose-600 hover:!text-rose-700"
                    @click="confirmDelete(l)">Delete</button>
          </div>
        </div>
      </div>

      <!-- pagination -->
      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
        <span>Showing {{ leads.from ?? 0 }}–{{ leads.to ?? 0 }} of {{ leads.total }} leads</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in leads.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <LeadFormModal :show="formOpen" :lead="editing" :options="options" @close="formOpen = false" />
    <LeadViewModal :show="viewOpen" :lead-id="viewId" :options="options"
                   @close="viewOpen = false" @edit="editFromView" />
    <ConfirmDialog
      :show="confirmOpen" :processing="processing"
      title="Delete this lead?"
      :message="`${deleting?.full_name} · ${deleting?.mobile_number} will be removed from all lists and reports.`"
      confirm-text="Delete lead"
      @close="confirmOpen = false" @confirm="doDelete"
    />
  </AppLayout>
</template>
