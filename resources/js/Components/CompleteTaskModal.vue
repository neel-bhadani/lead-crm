<script setup>
import { watch, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import StageBadge from './StageBadge.vue'
import CallButtons from './CallButtons.vue'

const props = defineProps({ show: Boolean, todo: Object, options: Object })
const emit = defineEmits(['close'])

const form = useForm({
  remarks: '', stage: 'connected',
  visit_at: '', reason: '', booked_unit: '', booking_date: '',
})

watch(() => props.show, v => {
  if (!v) return
  form.reset()
  form.clearErrors()
  // start from the lead's current stage, except fresh which always moves on
  form.stage = props.todo?.lead?.stage === 'fresh' ? 'connected' : props.todo?.lead?.stage
})

const showVisit  = computed(() => form.stage === 'site_visit_scheduled')
const showReason = computed(() => form.stage === 'lost')
const showUnit   = computed(() => form.stage === 'booking_done')

watch(showVisit,  v => { if (!v) form.visit_at = '' })
watch(showReason, v => { if (!v) form.reason = '' })
watch(showUnit,   v => { if (!v) form.booked_unit = '' })

/*
 | The user never picks the next follow-up date — the stage decides it, and so
 | do the working hours, the working days and the holidays behind it. None of
 | that is knowable here, so the server sends the finished line for every stage
 | and this only chooses which one to show.
 |
 | The strings are computed when the page loads, so one left open across a
 | closing time can drift. The value that counts is the one the server works
 | out on save; this is a preview.
 */
const preview = computed(() => {
  // the one datetime the system does not choose: the customer picked it, and
  // it is saved exactly as entered, working hours or not
  if (form.stage === 'site_visit_scheduled') {
    return 'The site visit above is saved exactly as you entered it, and becomes the next task. The lead also moves to a salesperson.'
  }

  return props.todo?.follow_up_previews?.[form.stage] ?? ''
})

const submit = () => form.post(route('todos.complete', props.todo.id), {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})
</script>

<template>
  <Modal :show="show" :title="`Log call — ${todo?.lead?.full_name ?? ''}`" @close="emit('close')">

    <div class="mb-5 grid gap-4 sm:grid-cols-2">
      <div>
        <div class="text-[11px] font-semibold text-slate-400">Mobile</div>
        <CallButtons v-if="todo?.lead?.mobile_number" class="mt-1" :mobile="todo.lead.mobile_number" />
        <div v-else class="text-sm">—</div>
      </div>
      <div>
        <div class="text-[11px] font-semibold text-slate-400">Current stage</div>
        <div class="mt-0.5"><StageBadge v-if="todo?.lead" :stage="todo.lead.stage" /></div>
      </div>
    </div>

    <FormField label="What happened on the call?" required :error="form.errors.remarks">
      <textarea v-model="form.remarks" rows="3"
                placeholder="Spoke to customer, asked for the floor plan…"></textarea>
    </FormField>

    <FormField class="mt-4" label="New stage" required :error="form.errors.stage">
      <select v-model="form.stage">
        <option v-for="(l, k) in options.stages" :key="k" :value="k">{{ l }}</option>
      </select>
    </FormField>

    <FormField v-if="showVisit" class="mt-4" label="Site visit date and time" required
               :error="form.errors.visit_at">
      <input v-model="form.visit_at" type="datetime-local" />
    </FormField>

    <FormField v-if="showReason" class="mt-4" label="Reason for loss" required :error="form.errors.reason">
      <select v-model="form.reason">
        <option value="">Select a reason</option>
        <option v-for="(l, k) in options.reasons" :key="k" :value="k">{{ l }}</option>
      </select>
    </FormField>

    <FormField v-if="showUnit" class="mt-4" label="Unit booked" required :error="form.errors.booked_unit">
      <input v-model="form.booked_unit" type="text" placeholder="A-402" />
    </FormField>

    <div class="info-box mt-5">{{ preview }}</div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : 'Save and schedule next' }}
      </button>
    </template>
  </Modal>
</template>
