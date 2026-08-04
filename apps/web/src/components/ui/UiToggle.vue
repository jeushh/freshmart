<script setup>
const props = defineProps({
  modelValue: Boolean,
  label: { type: String, required: true },
  description: { type: String, default: '' },
  disabled: Boolean
})

const emit = defineEmits(['update:modelValue', 'change'])

function toggle() {
  if (props.disabled) return
  const value = !props.modelValue
  emit('update:modelValue', value)
  emit('change', value)
}
</script>

<template>
  <div class="ui-toggle-field">
    <button
      class="ui-toggle"
      type="button"
      role="switch"
      :aria-checked="modelValue"
      :aria-label="label"
      :disabled="disabled"
      @click="toggle"
    >
      <span class="ui-toggle__thumb" aria-hidden="true"></span>
    </button>
    <span class="ui-toggle-field__copy">
      <span>{{ label }}</span>
      <span v-if="description" class="ui-toggle-field__description">{{ description }}</span>
    </span>
  </div>
</template>
