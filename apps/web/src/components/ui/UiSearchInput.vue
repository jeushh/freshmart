<script setup>
import { computed, useId } from 'vue'
import UiButton from './UiButton.vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: String, default: '' },
  id: { type: String, default: '' },
  label: { type: String, default: 'Search' },
  placeholder: { type: String, default: 'Search…' },
  disabled: Boolean,
  loading: Boolean
})

const emit = defineEmits(['update:modelValue', 'search'])
const generatedId = useId()
const fieldId = computed(() => props.id || generatedId)

function update(value) {
  emit('update:modelValue', value)
  emit('search', value)
}
</script>

<template>
  <label class="ui-field ui-search" :for="fieldId">
    <span class="ui-field__label">{{ label }}</span>
    <span class="ui-field__control-wrap">
      <svg class="ui-search__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5" />
        <path d="m13 13 4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
      </svg>
      <input
        v-bind="$attrs"
        :id="fieldId"
        class="ui-field-control"
        type="search"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        @input="update($event.target.value)"
      >
      <UiButton
        v-if="modelValue && !disabled"
        class="ui-search__clear"
        variant="ghost"
        size="sm"
        icon-only
        aria-label="Clear search"
        @click="update('')"
      >
        <span aria-hidden="true">×</span>
      </UiButton>
    </span>
  </label>
</template>
