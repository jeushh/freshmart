<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  type: { type: String, default: 'button' },
  variant: {
    type: String,
    default: 'primary',
    validator: value => ['primary', 'secondary', 'ghost', 'destructive'].includes(value)
  },
  size: {
    type: String,
    default: 'md',
    validator: value => ['sm', 'md', 'lg'].includes(value)
  },
  disabled: Boolean,
  loading: Boolean,
  iconOnly: Boolean,
  ariaLabel: { type: String, default: '' },
  loadingLabel: { type: String, default: 'Loading' }
})

const classes = computed(() => [
  `ui-button--${props.variant}`,
  `ui-button--${props.size}`,
  { 'ui-button--icon': props.iconOnly }
])
const button = ref(null)

defineExpose({
  focus: () => button.value?.focus()
})
</script>

<template>
  <button
    ref="button"
    class="ui-button"
    :class="classes"
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading || undefined"
    :aria-label="ariaLabel || undefined"
  >
    <span v-if="loading" class="ui-spinner" aria-hidden="true"></span>
    <span v-if="loading" class="ui-sr-only">{{ loadingLabel }}</span>
    <template v-else>
      <slot name="leading"></slot>
      <slot></slot>
      <slot name="trailing"></slot>
    </template>
  </button>
</template>
