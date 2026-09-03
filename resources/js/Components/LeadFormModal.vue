<script setup>
import { ref, watch, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { toast } from '@/composables/useToast'

const props = defineProps({
  show: Boolean,
  lead: Object,          // null when adding
  options: Object,
})
const emit = defineEmits(['close'])

const blank = {
  first_name: '', middle_name: '', last_name: '',
  mobile_number: '', email: '',
  project_id: '', source: 'walk_in',
  broker_name: '', stage: 'fresh', reason: '', booked_unit: '',
}

const form = useForm({ ...blank })
const duplicate = ref(null)

watch(() => props.show, v => {
  duplicate.value = null
  form.clearErrors()

  if (!v) return

  if (props.lead) {
    Object.keys(blank).forEach(k => (form[k] = props.lead[k] ?? blank[k]))
  } else {
    Object.assign(form, blank)
    form.project_id = props.options.projects[0]?.id ?? ''
  }
})

/* conditional fields */
const showBroker = computed(() => form.source === 'broker')
const showReason = computed(() => form.stage === 'lost')
const showUnit   = computed(() => form.stage === 'booking_done')

// clear a hidden field, or a stale value gets saved
watch(showBroker, v => { if (!v) form.broker_name = '' })
watch(showReason, v => { if (!v) form.reason = '' })
watch(showUnit,   v => { if (!v) form.booked_unit = '' })

/* live duplicate check — the unique index is the real guarantee */
let timer
const checkDuplicate = () => {
  clearTimeout(timer)
  duplicate.value = null

  if (String(form.mobile_number).length !== 10 || !form.project_id) return

  timer = setTimeout(async () => {
    try {
      const { data } = await axios.post(route('leads.check-duplicate'), {
        mobile_number: form.mobile_number,
        project_id: form.project_id,
        lead_id: props.lead?.id,
      })
      duplicate.value = data.exists ? data.message : null
    } catch (e) {
      // 422 only means the number or project is not usable yet — nothing to say.
      // Anything else and the check genuinely did not run, so warn: the unique
      // index will still reject a duplicate, but not until save.
      if (e?.response?.status !== 422) {
        toast.warning('Could not check for a duplicate number. Please verify before saving.')
      }
    }
  }, 400)
}
watch(() => [form.mobile_number, form.project_id], checkDuplicate)

/*
 | Follow-up preview, computed by the server: the hours it depends on, and the
 | working days and holidays that move them, live in config/crm.php and nothing
 | here reimplements them.
 |
 | Adding and editing are different questions. A new lead is called as soon as
 | the office is open; an existing one whose stage is being changed gets the
 | interval for that stage. The two maps come down separately.
 */
const preview = computed(() => {
  const previews = props.lead?.follow_up_previews ?? props.options.followUpPreviews

  return previews?.[form.stage] ?? ''
})

const submit = () => {
  const opts = {
    preserveScroll: true,
    onSuccess: () => { emit('close'); form.reset() },
  }
  props.lead
    ? form.put(route('leads.update', props.lead.id), opts)
    : form.post(route('leads.store'), opts)
}
</script>

<template>
  <Modal :show="show" :title="lead ? 'Edit lead' : 'Add lead'" @close="emit('close')">

    <div class="grid gap-4 sm:grid-cols-3">
      <FormField label="First name" required :error="form.errors.first_name">
        <input v-model="form.first_name" type="text" />
      </FormField>
      <FormField label="Middle name" :error="form.errors.middle_name">
        <input v-model="form.middle_name" type="text" />
      </FormField>
      <FormField label="Last name" required :error="form.errors.last_name">
        <input v-model="form.last_name" type="text" />
      </FormField>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Mobile number" required :error="form.errors.mobile_number">
        <input v-model="form.mobile_number" type="text" maxlength="10" inputmode="numeric" />
        <div v-if="duplicate" class="warn-box mt-2">{{ duplicate }}</div>
      </FormField>
      <FormField label="Email" :error="form.errors.email">
        <input v-model="form.email" type="email" />
      </FormField>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Project" required :error="form.errors.project_id">
        <select v-model="form.project_id">
          <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </FormField>
      <FormField label="Source" required :error="form.errors.source">
        <select v-model="form.source">
          <option v-for="(label, key) in options.sources" :key="key" :value="key">{{ label }}</option>
        </select>
      </FormField>
    </div>

    <FormField v-if="showBroker" class="mt-4" label="Broker name" required :error="form.errors.broker_name">
      <input v-model="form.broker_name" type="text" />
    </FormField>

    <FormField class="mt-4" label="Stage" required :error="form.errors.stage">
      <select v-model="form.stage">
        <option v-for="(label, key) in options.stages" :key="key" :value="key">{{ label }}</option>
      </select>
    </FormField>

    <FormField v-if="showReason" class="mt-4" label="Reason for loss" required :error="form.errors.reason">
      <select v-model="form.reason">
        <option value="">Select a reason</option>
        <option v-for="(label, key) in options.reasons" :key="key" :value="key">{{ label }}</option>
      </select>
    </FormField>

    <FormField v-if="showUnit" class="mt-4" label="Unit booked" required :error="form.errors.booked_unit">
      <input v-model="form.booked_unit" type="text" placeholder="A-402" />
    </FormField>

    <div class="info-box mt-5">{{ preview }}</div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (lead ? 'Save changes' : 'Add lead') }}
      </button>
    </template>
  </Modal>
</template>
