<script setup>
import UiPageHeader from '../ui/UiPageHeader.vue'

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' }
})
</script>

<template>
  <UiPageHeader :title="title" :description="description">
    <slot></slot>
  </UiPageHeader>
</template>
