<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'

/*
 | The alerts list, rendered once and used twice: the /alerts page every user
 | can open, and the Alerts tab on the Automation page. Two copies would drift
 | the day somebody adds a severity, and the copy that was not updated would
 | quietly stop showing rows.
 |
 | Severity is colour and nothing else. It does not change who is told, when
 | they are told, or whether the alert deduplicates — it is the difference
 | between "worth knowing" and "deal with this today", drawn so a full list can
 | be skimmed.
 */
const props = defineProps({
  alerts: { type: Object, required: true },      // a Laravel paginator
  filters: { type: Object, required: true },
  counts: { type: Object, required: true },
  thresholds: { type: Object, default: () => ({}) },
  // the Automation tab shows the first page and points at /alerts for the rest
  paginate: { type: Boolean, default: true },
  /*
   | Where the filter buttons navigate to.
   |
   | The same list is rendered on two different routes, and the filters have to
   | stay on whichever one the reader is standing on — a severity chip on the
   | Automation page's Alerts tab that jumped to /alerts would throw away the
   | four other tabs they were working in. Both routes resolve the filters the
   | same way, through the ListsAlerts trait, so only the destination differs.
   */
  routeName: { type: String, default: 'alerts.index' },
  routeParams: { type: Object, default: () => ({}) },
})

const rows = computed(() => props.alerts.data ?? [])

const tone = severity => ({
  urgent: { dot: 'bg-rose-500', chip: 'bg-rose-50 text-rose-700 border-rose-200', label: 'Urgent' },
  warning: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-800 border-amber-200', label: 'Warning' },
}[severity] ?? { dot: 'bg-slate-300', chip: 'bg-slate-50 text-slate-600 border-slate-200', label: 'Info' })

const go = params => router.get(route(props.routeName, { ...props.routeParams, ...params }), {}, {
  preserveScroll: true,
  preserveState: true,
  replace: true,
})

const setStatus = status => go({ ...props.filters, status })
const setSeverity = severity => go({ ...props.filters, severity })

const openAlert = alert => router.post(route('alerts.read', alert.id), {}, { preserveScroll: true })

const markAllRead = () => router.post(route('alerts.read-all'), {}, { preserveScroll: true })

const when = iso => {
  if (!iso) return ''

  return new Date(iso).toLocaleString('en-IN', {
    day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit',
  })
}
</script>

<template>
  <div>
    <!-- ---------------- filters ---------------- -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="s in [
            { key: 'all', label: `All (${counts.all})` },
            { key: 'unread', label: `Unread (${counts.unread})` },
            { key: 'read', label: 'Read' },
          ]"
          :key="s.key" class="btn-xs"
          :class="filters.status === s.key ? 'border-teal-600 bg-teal-50 text-teal-700' : ''"
          @click="setStatus(s.key)"
        >{{ s.label }}</button>
      </div>

      <span class="hidden h-4 w-px bg-slate-200 sm:block" />

      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="s in [
            { key: 'all', label: 'Any level' },
            { key: 'urgent', label: `Urgent (${counts.urgent})` },
            { key: 'warning', label: `Warning (${counts.warning})` },
            { key: 'info', label: `Info (${counts.info})` },
          ]"
          :key="s.key" class="btn-xs"
          :class="filters.severity === s.key ? 'border-teal-600 bg-teal-50 text-teal-700' : ''"
          @click="setSeverity(s.key)"
        >{{ s.label }}</button>
      </div>

      <button v-if="counts.unread" class="btn-xs ml-auto" @click="markAllRead">Mark all read</button>
    </div>

    <!-- ---------------- empty ---------------- -->
    <!--
      Not "No alerts". Somebody looking at an empty list on their first day
      should come away knowing what would have been in it and how it differs
      from the Follow-ups page they already use.
    -->
    <div v-if="!rows.length" class="card px-6 py-10 text-center">
      <p class="text-base font-semibold text-slate-800">
        {{ filters.status === 'all' && filters.severity === 'all'
          ? 'Nothing to tell you yet'
          : 'Nothing matches those filters' }}
      </p>

      <template v-if="filters.status === 'all' && filters.severity === 'all'">
        <p class="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-slate-500">
          An alert is something you should know about — a follow-up that has gone overdue, a lead
          that has stopped moving, or an automation rule flagging something. It is
          <strong>not</strong> a task: reading an alert changes nothing about the lead, and it
          never appears on your Follow-ups page.
        </p>
        <p v-if="thresholds.overdue_days" class="mx-auto mt-3 max-w-lg text-xs text-slate-400">
          You will be told automatically when a follow-up is more than
          {{ thresholds.overdue_days }} days overdue, or when a lead has sat in one stage for more
          than {{ thresholds.stuck_days }} days. The same alert is never repeated within
          {{ thresholds.dedupe_hours }} hours.
        </p>
      </template>

      <button v-else class="btn-ghost mt-4" @click="go({ status: 'all', severity: 'all' })">
        Clear filters
      </button>
    </div>

    <!-- ---------------- rows ---------------- -->
    <div v-else class="card divide-y divide-slate-100">
      <button
        v-for="alert in rows" :key="alert.id"
        class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition hover:bg-slate-50"
        :class="alert.read_at ? 'opacity-60' : ''"
        @click="openAlert(alert)"
      >
        <span class="mt-1.5 h-2 w-2 flex-none rounded-full" :class="tone(alert.severity).dot" />

        <span class="min-w-0 flex-1">
          <span class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-slate-900">{{ alert.title }}</span>
            <span
              class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
              :class="tone(alert.severity).chip"
            >{{ tone(alert.severity).label }}</span>
            <span v-if="!alert.read_at"
                  class="rounded bg-teal-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase
                         tracking-wide text-teal-700">New</span>
          </span>

          <span v-if="alert.body" class="mt-1 block text-xs leading-relaxed text-slate-600">
            {{ alert.body }}
          </span>

          <span class="mt-1.5 block text-[11px] text-slate-400">
            {{ when(alert.created_at) }}
            <template v-if="alert.rule"> · from the rule “{{ alert.rule.name }}”</template>
            <template v-else> · raised automatically</template>
          </span>
        </span>
      </button>
    </div>

    <!-- ---------------- paging ---------------- -->
    <div v-if="paginate && alerts.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
      <component
        :is="link.url ? Link : 'span'"
        v-for="link in alerts.links" :key="link.label"
        :href="link.url" preserve-scroll
        class="rounded-md border px-2.5 py-1 text-xs"
        :class="link.active
          ? 'border-teal-600 bg-teal-50 font-semibold text-teal-700'
          : link.url ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                     : 'border-slate-100 bg-white text-slate-300'"
        v-html="link.label"
      />
    </div>

    <div v-else-if="!paginate && alerts.total > rows.length" class="mt-3 text-center">
      <Link :href="route('alerts.index')" class="text-xs font-medium text-teal-700 hover:underline">
        See all {{ alerts.total }} alerts
      </Link>
    </div>
  </div>
</template>
