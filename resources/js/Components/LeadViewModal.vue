<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import Modal from './Modal.vue'
import StageBadge from './StageBadge.vue'
import { brokerLabel } from '@/lib/brokerLabel.js'

const props = defineProps({ show: Boolean, leadId: Number, options: Object })
const emit = defineEmits(['close', 'edit'])

const lead = ref(null)
const loading = ref(false)

watch(() => props.show, async v => {
  if (!v || !props.leadId) { lead.value = null; return }

  loading.value = true
  try {
    const { data } = await axios.get(route('leads.show', props.leadId))
    lead.value = data.lead
  } finally {
    loading.value = false
  }
})

const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

const color = s => props.options.stageColors?.[s] ?? '#8A94A0'
</script>

<template>
  <Modal :show="show" :title="lead?.full_name ?? 'Lead'" @close="emit('close')">

    <div v-if="loading" class="py-10 text-center text-sm text-slate-500">Loading…</div>

    <div v-else-if="lead">
      <dl class="grid gap-4 sm:grid-cols-2">
        <div><dt class="text-[11px] font-semibold text-slate-400">Mobile</dt>
             <dd class="text-sm">{{ lead.mobile_number }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Email</dt>
             <dd class="break-all text-sm">{{ lead.email || '—' }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Project</dt>
             <dd class="text-sm">{{ lead.project?.name }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Source</dt>
             <dd class="text-sm">
               {{ options.sources[lead.source] }}
               <!-- the partner row if the lead has one, the old free text if it
                    does not — see lib/brokerLabel.js -->
               <span v-if="brokerLabel(lead)" class="text-slate-400">· {{ brokerLabel(lead) }}</span>
             </dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Stage</dt>
             <dd class="mt-0.5"><StageBadge :stage="lead.stage" /></dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Owner</dt>
             <dd class="text-sm">{{ lead.owner?.display_name ?? '—' }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Days in current stage</dt>
             <dd class="text-sm">{{ lead.days_in_stage === null ? '—' : lead.days_in_stage + ' days' }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Next follow-up</dt>
             <dd class="text-sm">{{ lead.pending_todo ? fmt(lead.pending_todo.scheduled_at) : 'None — lead is closed' }}</dd></div>
        <div v-if="lead.reason"><dt class="text-[11px] font-semibold text-slate-400">Reason for loss</dt>
             <dd class="text-sm">{{ options.reasons[lead.reason] }}</dd></div>
        <div v-if="lead.booked_unit"><dt class="text-[11px] font-semibold text-slate-400">Booked unit</dt>
             <dd class="text-sm">{{ lead.booked_unit }}</dd></div>
      </dl>

      <div class="mt-6 border-t border-slate-100 pt-5">
        <h4 class="mb-3 text-xs font-semibold text-slate-500">Activity</h4>

        <p v-if="!lead.completed_todos?.length" class="text-sm text-slate-400">No calls logged yet.</p>

        <div v-for="(t, i) in lead.completed_todos" :key="t.id" class="relative flex gap-3 pb-4">
          <span v-if="i < lead.completed_todos.length - 1"
                class="absolute left-[5px] top-4 bottom-0 w-px bg-slate-200"></span>
          <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full"
                :style="{ backgroundColor: color(t.outcome_stage) }"></span>
          <div class="min-w-0">
            <div class="text-sm font-semibold">{{ options.stages[t.outcome_stage] }}</div>
            <div class="text-xs text-slate-500">{{ t.remarks || '—' }}</div>
            <div class="mt-0.5 text-[11px] text-slate-400">
              {{ fmt(t.completed_at) }} · {{ t.completer?.display_name ?? '—' }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Close</button>
      <button class="btn flex-1 sm:flex-none" @click="emit('edit', leadId)">Edit lead</button>
    </template>
  </Modal>
</template>
