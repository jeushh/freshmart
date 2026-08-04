<script setup>
import { useId } from 'vue'

const props = defineProps({
  modelValue: Boolean,
  id: { type: String, default: '' },
  label: { type: String, required: true },
  description: { type: String, default: '' },
  disabled: Boolean,
  required: Boolean
})

const emit = defineEmits(['update:modelValue', 'change'])
const generatedId = useId()
const fieldId = props.id || generatedId
</script>

<template>
  <label class="ui-check" :class="{ 'ui-check--disabled': disabled }" :for="fieldId">
    <input
      :id="fieldId"
      class="ui-check__input"
      type="checkbox"
      :checked="modelValue"
      :disabled="disabled"
      :required="required"
      @change="emit('update:modelValue', $event.target.checked); emit('change', $event)"
    >
    <span class="ui-check__copy">
      <span>{{ label }}</span>
      <span v-if="description" class="ui-check__description">{{ description }}</span>
    </span>
  </label>
</template>
