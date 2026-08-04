<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true },
  tone: {
    type: String,
    default: '',
    validator: value => ['', 'success', 'warning', 'danger', 'info', 'neutral'].includes(value)
  },
  label: { type: String, default: '' }
})

const inferredTone = computed(() => {
  if (props.tone) return props.tone
  const status = props.status.toLowerCase()
  if (['approved', 'active', 'completed', 'paid', 'success', 'fully received'].includes(status)) return 'success'
  if (['pending', 'pending approval', 'submitted', 'partially received'].includes(status)) return 'warning'
  if (['rejected', 'inactive', 'failed', 'cancelled', 'overdue'].includes(status)) return 'danger'
  if (['processing', 'ordered', 'receiving'].includes(status)) return 'info'
  return 'neutral'
})
</script>

<template>
  <span class="ui-badge" :class="`ui-badge--${inferredTone}`">
    <span class="ui-badge__dot" aria-hidden="true"></span>
    {{ label || status }}
  </span>
</template>
