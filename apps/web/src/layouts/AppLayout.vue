<template>
  <div class="fm-shell">
    <aside class="fm-shell__sidebar" aria-label="Application sidebar">
      <!-- Step 6 owns all sidebar navigation content. -->
      <slot name="sidebar"></slot>
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
