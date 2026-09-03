<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

const props = defineProps({ show: Boolean, todo: Object, options: Object })
const emit = defineEmits(['close'])

const local = d => {
  const x = new Date(d)
  const p = n => String(n).padStart(2, '0')
  return `${x.getFullYear()}-${p(x.getMonth() + 1)}-${p(x.getDate())}T${p(x.getHours())}:${p(x.getMinutes())}`
}

const form = useForm({ lead_id: '', type: 'call', scheduled_at: '', remarks: '' })

watch(() => props.show, v => {
  if (!v) return
  form.clearErrors()

  if (props.todo) {
    form.lead_id = props.todo.lead_id
    form.type = props.todo.type
    form.scheduled_at = local(props.todo.scheduled_at)
    form.remarks = props.todo.remarks ?? ''
  } else {
    form.reset()
    form.lead_id = props.options.openLeads[0]?.id ?? ''
    form.scheduled_at = local(Date.now() + 864e5)
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
  <Modal :show="show" :title="todo ? 'Reschedule task' : 'Add to-do'" @close="emit('close')">

    <div v-if="todo" class="info-box mb-4">
      {{ todo.lead?.full_name ?? 'Lead deleted' }} · {{ todo.lead?.mobile_number ?? '—' }}
    </div>

    <FormField v-else label="Lead" required :error="form.errors.lead_id">
      <select v-model="form.lead_id">
        <option v-if="!options.openLeads.length" value="">Every open lead already has a task</option>
        <option v-for="l in options.openLeads" :key="l.id" :value="l.id">
          {{ l.first_name }} {{ l.last_name }} — {{ l.mobile_number }}
        </option>
      </select>
      <!-- only leads without an open task appear: one pending to-do per lead -->
      <div class="warn-box mt-2">
        Only leads without an open task appear here. A lead can hold just one pending to-do.
      </div>
    </FormField>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Type" required :error="form.errors.type">
        <select v-model="form.type">
          <option v-for="(l, k) in options.types" :key="k" :value="k">{{ l }}</option>
        </select>
      </FormField>
      <FormField label="Scheduled at" required :error="form.errors.scheduled_at">
        <input v-model="form.scheduled_at" type="datetime-local" />
      </FormField>
    </div>

    <FormField class="mt-4" label="Remarks" :error="form.errors.remarks">
      <textarea v-model="form.remarks" rows="2"></textarea>
    </FormField>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (todo ? 'Save changes' : 'Add to-do') }}
      </button>
    </template>
  </Modal>
</template>
