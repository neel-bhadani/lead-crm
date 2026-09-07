<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { toLocalInput, defaultFollowUp, isPast } from '@/lib/localDateTime'
import { useFutureDateTime } from '@/composables/useFutureDateTime'
import { useFollowUpConflict } from '@/composables/useFollowUpConflict'

const props = defineProps({ show: Boolean, todo: Object, options: Object })
const emit = defineEmits(['close'])

const form = useForm({ lead_id: '', type: 'call', scheduled_at: '', remarks: '' })

// the past-date guard; the message matches the one TodoRequest sends back, so
// a bypassed `min` reads the same either way
const { min: minAt, refresh: refreshMinAt, past: datePast, error: dateError } =
  useFutureDateTime(() => form.scheduled_at)

/*
 | Is this time already spoken for?
 |
 | Both of this modal's jobs are covered by the one check. Rescheduling asks
 | about the person already holding the task and excludes the task itself —
 | without that, every reschedule would report a clash with the row being
 | moved. Adding asks about whoever owns the lead that is selected, which is why
 | `openLeads` carries assigned_to.
 |
 | A warning, nothing more: the button below is not disabled by it.
 */
const { conflict, clear: clearConflict } = useFollowUpConflict({
  user: () => props.todo
    ? props.todo.assigned_to
    : props.options.openLeads.find(l => l.id === form.lead_id)?.assigned_to,
  at: () => form.scheduled_at,
  exclude: () => props.todo?.id ?? null,
})

watch(() => props.show, v => {
  // a warning about the last follow-up this modal was opened for must not be
  // the first thing on screen the next time it opens
  clearConflict()

  if (!v) return
  form.clearErrors()
  // now, as of this opening — not as of whenever the page was loaded
  refreshMinAt()

  if (props.todo) {
    form.lead_id = props.todo.lead_id
    form.type = props.todo.type

    /*
     | The reschedule case, and the one place the two rules could contradict
     | each other. A task is rescheduled because it is overdue far more often
     | than not, so echoing its current datetime back would open the form on a
     | value the form itself rejects — `min` refusing it, the guard below
     | reporting it, and the submit button shut before the user has touched
     | anything. The record is not at fault and is not being re-validated;
     | only the value being submitted has to be in the future. So a stale date
     | is replaced by the same default the add form uses, and a task that is
     | still ahead of us is offered back exactly as it stands.
     */
    form.scheduled_at = isPast(props.todo.scheduled_at)
      ? defaultFollowUp()
      : toLocalInput(props.todo.scheduled_at)

    form.remarks = props.todo.remarks ?? ''
  } else {
    form.reset()
    form.lead_id = props.options.openLeads[0]?.id ?? ''
    form.scheduled_at = defaultFollowUp()
  }
})

const submit = () => {
  const opts = { preserveScroll: true, onSuccess: () => emit('close') }
  props.todo
    ? form.put(route('todos.update', props.todo.id), opts)
    : form.post(route('todos.store'), opts)
}
</script>

<template>
  <Modal :show="show" :title="todo ? 'Reschedule follow-up' : 'Add follow-up'" @close="emit('close')">

    <div v-if="todo" class="info-box mb-4">
      {{ todo.lead?.full_name ?? 'Lead deleted' }} · {{ todo.lead?.mobile_number ?? '—' }}
    </div>

    <FormField v-else label="Lead" required :error="form.errors.lead_id">
      <select v-model="form.lead_id">
        <option v-if="!options.openLeads.length" value="">Every open lead already has a follow-up</option>
        <option v-for="l in options.openLeads" :key="l.id" :value="l.id">
          {{ l.first_name }} {{ l.last_name }} — {{ l.mobile_number }}
        </option>
      </select>
      <!-- only leads without an open task appear: one pending to-do per lead -->
      <div class="warn-box mt-2">
        Only leads without an open follow-up appear here. A lead can hold just one pending follow-up.
      </div>
    </FormField>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Type" required :error="form.errors.type">
        <select v-model="form.type">
          <option v-for="(l, k) in options.types" :key="k" :value="k">{{ l }}</option>
        </select>
      </FormField>
      <FormField label="Scheduled at" required :error="form.errors.scheduled_at || dateError">
        <input v-model="form.scheduled_at" type="datetime-local" :min="minAt" />
        <!-- a warning, not a refusal: this time saves if the user keeps it -->
        <div v-if="conflict" class="warn-box mt-2">{{ conflict }}</div>
      </FormField>
    </div>

    <FormField class="mt-4" label="Remarks" :error="form.errors.remarks">
      <textarea v-model="form.remarks" rows="2"></textarea>
    </FormField>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none"
              :disabled="form.processing || datePast" @click="submit">
        {{ form.processing ? 'Saving…' : (todo ? 'Save changes' : 'Add follow-up') }}
      </button>
    </template>
  </Modal>
</template>
