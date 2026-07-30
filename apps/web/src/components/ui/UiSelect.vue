<script setup>
import { computed, useId } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  id: { type: String, default: '' },
  label: { type: String, required: true },
  size: {
    type: String,
    default: 'md',
    validator: value => ['sm', 'md', 'lg'].includes(value)
  },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
  required: Boolean,
  disabled: Boolean,
  loading: Boolean
})

const emit = defineEmits(['update:modelValue', 'change'])
const generatedId = useId()
const fieldId = computed(() => props.id || generatedId)
const descriptionId = computed(() => props.error || props.hint ? `${fieldId.value}-description` : undefined)
</script>

<template>
  <label class="ui-field" :for="fieldId">
    <span class="ui-field__label">
      {{ label }}
      <span v-if="required" class="ui-field__required" aria-hidden="true">*</span>
    </span>
    <select
      v-bind="$attrs"
      :id="fieldId"
      class="ui-field-control"
      :class="`ui-field-control--${size}`"
      :value="modelValue"
      :required="required"
      :disabled="disabled || loading"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="descriptionId"
      :aria-busy="loading || undefined"
      @change="emit('update:modelValue', $event.target.value); emit('change', $event)"
    >
      <slot></slot>
    </select>
    <p v-if="error" :id="descriptionId" class="ui-field__error" role="alert">{{ error }}</p>
    <p v-else-if="hint" :id="descriptionId" class="ui-field__hint">{{ hint }}</p>
  </label>
</template>
