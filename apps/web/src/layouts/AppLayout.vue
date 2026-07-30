<script setup>
import AppSidebar from '../components/navigation/AppSidebar.vue'
import { useSidebarState } from '../composables/useSidebarState.js'

const { collapsed, mobileOpen } = useSidebarState()
</script>

<template>
  <div class="fm-shell" :class="{ 'fm-shell--sidebar-collapsed': collapsed }">
    <aside
      class="fm-shell__sidebar"
      :class="{ 'fm-shell__sidebar--open': mobileOpen }"
      aria-label="Application sidebar"
    >
      <AppSidebar />
    </aside>

    <div class="fm-shell__body">
      <header class="fm-shell__header">
        <!-- Step 7 owns all top-header content. -->
        <slot name="header"></slot>
      </header>

      <main id="main-content" class="fm-shell__main" tabindex="-1">
        <div
          v-if="$slots.breadcrumbs || $slots.pageActions"
          class="fm-shell__context"
        >
          <nav
            v-if="$slots.breadcrumbs"
            class="fm-shell__breadcrumbs"
            aria-label="Breadcrumb"
          >
            <slot name="breadcrumbs"></slot>
          </nav>
          <div v-if="$slots.pageActions" class="fm-shell__page-actions">
            <slot name="pageActions"></slot>
          </div>
        </div>

        <div class="fm-shell__content">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
