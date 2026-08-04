<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  eyebrow: { type: String, default: '' },
  breadcrumbs: { type: Array, default: () => [] }
})
</script>

<template>
  <header class="ui-page-header">
    <div>
      <nav v-if="breadcrumbs.length" aria-label="Breadcrumb">
        <ol class="ui-page-header__breadcrumbs">
          <li v-for="(breadcrumb, index) in breadcrumbs" :key="breadcrumb.label">
            <RouterLink v-if="breadcrumb.to && index < breadcrumbs.length - 1" :to="breadcrumb.to">
              {{ breadcrumb.label }}
            </RouterLink>
            <span v-else :aria-current="index === breadcrumbs.length - 1 ? 'page' : undefined">
              {{ breadcrumb.label }}
            </span>
          </li>
        </ol>
      </nav>
      <p v-if="eyebrow" class="ui-page-header__eyebrow">{{ eyebrow }}</p>
      <h1 class="ui-page-header__title">{{ title }}</h1>
      <p v-if="description" class="ui-page-header__description">{{ description }}</p>
    </div>
    <div v-if="$slots.actions || $slots.default" class="ui-page-header__actions">
      <slot name="actions"></slot>
      <slot></slot>
    </div>
  </header>
</template>
