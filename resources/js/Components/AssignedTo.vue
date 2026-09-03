<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * A staff member as a table cell: the name in semibold with their role on a
 * muted sub-line under it, the same shape the Name column uses for a lead and
 * its email. One component for all five places it appears, so the two columns
 * line up rather than drifting apart.
 */
const props = defineProps({ user: Object })

// config/crm.php owns the wording and reaches here through the page's options
// prop, the same way StageBadge reads its palette
const labels = computed(() => usePage().props.options?.roleLabels ?? {})

/*
 | assigned_to is nullable behind a nullOnDelete foreign key, so a deleted staff
 | member leaves the relation null. That row gets an em dash on the name line
 | and no sub-line at all — an empty grey line under a dash reads like something
 | failed to load.
 */
const role = computed(() =>
  props.user ? (labels.value[props.user.role] ?? props.user.role) : null
)
</script>

<template>
  <div>
    <div class="font-semibold">{{ user?.display_name ?? '—' }}</div>
    <div v-if="role" class="text-xs text-slate-400">{{ role }}</div>
  </div>
</template>
