<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | Add and edit a stage — one modal for both, the same shape as every other form
 | in this application.
 |
 | THE KEY IS SHOWN AND NEVER EDITABLE. On add it is a live preview of what the
 | server will slug the label into; on edit it is read-only text with the reason
 | printed beside it. Neither is sent: LeadStageRequest has no `key` rule, so a
 | key posted by hand is not in validated() and cannot reach the row.
 |
 | The preview is a convenience and not a promise. The server slugs the label
 | itself and appends a number if that key is taken, so what actually lands may
 | differ by a suffix — which is why the field says "will be" rather than naming
 | it as a fact.
 */
const props = defineProps({ show: Boolean, stage: Object, options: Object })
const emit = defineEmits(['close'])

const blank = { label: '', color: '', is_terminal: false, is_active: true }

const form = useForm({ ...blank })

const editing = computed(() => !!props.stage)

watch(() => props.show, open => {
  form.clearErrors()

  if (!open) return

  Object.assign(form, props.stage
    ? {
        ...blank,
        label: props.stage.label,
        color: props.stage.color,
        is_terminal: props.stage.is_terminal,
        is_active: props.stage.is_active,
      }
    // a new stage starts on the first palette colour rather than on nothing,
    // so the swatch row never renders with no selection
    : { ...blank, color: props.options.palette[0] })
})

/** What the server will slug the label into. See the note above. */
const keyPreview = computed(() =>
  (form.label || '')
    .toLowerCase()
    .replace(/['’]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 50) || '—')

const submit = () => {
  const done = { preserveScroll: true, onSuccess: () => emit('close') }

  editing.value
    ? form.put(route('pipeline.stages.update', props.stage.id), done)
    : form.post(route('pipeline.stages.store'), done)
}
</script>

<template>
  <Modal :show="show" :title="editing ? 'Edit stage' : 'Add stage'" max-width="max-w-lg" @close="emit('close')">
    <div class="space-y-4">

      <FormField label="Name" required :error="form.errors.label">
        <input v-model="form.label" type="text" maxlength="60" placeholder="e.g. Offer sent" />
      </FormField>

      <!--
        The key, always visible and never typeable. It is what `leads.stage` and
        every history row hold, so somebody reading a report or writing a seeder
        needs to be able to see it — and nobody may change it.
      -->
      <FormField
        label="Key"
        :hint="editing
          ? 'Fixed. Every lead and every follow-up in this stage is stored against this word.'
          : 'Made from the name. It cannot be changed afterwards.'"
      >
        <div class="rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm text-slate-500">
          {{ editing ? stage.key : keyPreview }}
        </div>
      </FormField>

      <FormField label="Colour" required :error="form.errors.color"
                 hint="The same colour in the badge, the funnel and every chart.">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="c in options.palette" :key="c" type="button"
            class="h-8 w-8 rounded-full ring-offset-2 transition"
            :class="form.color === c ? 'ring-2 ring-slate-900' : 'ring-1 ring-slate-200 hover:ring-slate-400'"
            :style="{ backgroundColor: c }"
            :aria-label="c" :aria-pressed="form.color === c"
            @click="form.color = c"
          />
        </div>
      </FormField>

      <!--
        Live, because the swatch row above is nine circles and none of them tells
        you what the badge will actually look like — a colour that reads well as
        a dot can be unreadable as text on its own 12% tint.
      -->
      <div v-if="form.color" class="flex items-center gap-2 text-xs text-slate-400">
        <span>Preview</span>
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
              :style="{ color: form.color, backgroundColor: form.color + '18' }">
          <span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ form.label || 'Stage' }}
        </span>
      </div>

      <!--
        Terminal. Frozen by the server whenever the answer would strand leads —
        see PipelineController::stageTerminalBlocker() — and the reason it gives
        is printed here rather than left as a mystery greyed-out box.
      -->
      <FormField label="Ends the lead's journey" :error="form.errors.is_terminal"
                 :hint="stage?.cannot_retype
                   || 'Booked and Lost work this way: the lead is closed and no follow-up is booked.'">
        <label class="flex items-center gap-2 text-sm"
               :class="stage?.cannot_retype ? 'cursor-not-allowed opacity-50' : ''">
          <input v-model="form.is_terminal" type="checkbox" :disabled="!!stage?.cannot_retype" />
          <span>No follow-up is scheduled when a lead reaches this stage</span>
        </label>
      </FormField>

      <FormField v-if="editing" label="In use" :error="form.errors.is_active"
                 :hint="stage?.cannot_deactivate
                   || 'Switching a stage off hides it from the dropdowns. Every lead already in it keeps it, and every past report still counts it.'">
        <label class="flex items-center gap-2 text-sm"
               :class="stage?.cannot_deactivate ? 'cursor-not-allowed opacity-50' : ''">
          <input v-model="form.is_active" type="checkbox" :disabled="!!stage?.cannot_deactivate" />
          <span>Offer this stage in the dropdowns</span>
        </label>
      </FormField>

      <p v-if="editing && stage.rules.length && form.is_active === false"
         class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
        {{ stage.rules.length === 1 ? 'An automation rule uses' : 'Automation rules use' }}
        this stage: {{ stage.rules.join(', ') }}.
        {{ stage.rules.length === 1 ? 'It' : 'They' }} will stop having an effect while the stage is off.
      </p>

    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : 'Save' }}
      </button>
    </template>
  </Modal>
</template>
