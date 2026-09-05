<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { navigationGroups } from '../../config/navigation.js'
import { useLogout } from '../../composables/useLogout.js'
import { useSidebarState } from '../../composables/useSidebarState.js'
import { sessionStore } from '../../stores/session.js'
import UiButton from '../ui/UiButton.vue'

const route = useRoute()
const nav = ref(null)
const mobileTrigger = ref(null)
const { logout, signingOut } = useLogout()
const {
  collapsed,
  mobileOpen,
  toggleCollapsed,
  openMobile,
  closeMobile
} = useSidebarState()

const visibleGroups = computed(() => navigationGroups
  .map(group => ({
    ...group,
    items: group.items.filter(item =>
      (!item.permission || sessionStore.can(item.permission))
      && !item.hideOnLanding?.includes(sessionStore.state.landingPage)
    )
  }))
  .filter(group => group.items.length))

const homePath = computed(() => sessionStore.homePath())

const displayName = computed(() =>
  sessionStore.state.user?.fullName
    || sessionStore.state.user?.username
    || 'FreshMart user'
)
const username = computed(() => sessionStore.state.user?.username || 'Signed in')
const initials = computed(() => displayName.value
  .split(/\s+/)
  .filter(Boolean)
  .slice(0, 2)
  .map(part => part[0]?.toUpperCase())
  .join('') || 'FM')

function isActive(path) {
  return route.path === path
}

async function showMobile() {
  openMobile()
  await nextTick()
  nav.value?.focus()
}

async function hideMobile(restoreFocus = false) {
  closeMobile()
  if (restoreFocus) {
    await nextTick()
    requestAnimationFrame(() => mobileTrigger.value?.focus())
  }
}

function onKeydown(event) {
  if (event.key === 'Escape' && mobileOpen.value) {
    event.preventDefault()
    hideMobile(true)
  }
}

watch(() => route.fullPath, () => closeMobile())
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <UiButton
      v-if="!mobileOpen"
      ref="mobileTrigger"
      class="fm-sidebar__mobile-trigger"
      variant="secondary"
      icon-only
      aria-label="Open navigation"
      :aria-expanded="mobileOpen"
      aria-controls="primary-sidebar-navigation"
      @click="showMobile"
    >
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </svg>
    </UiButton>
    <Transition name="fm-sidebar-backdrop">
      <button
        v-if="mobileOpen"
        class="fm-sidebar__backdrop"
        type="button"
        aria-label="Close navigation"
        @click="hideMobile(true)"
      ></button>
    </Transition>
  </Teleport>

  <div class="fm-sidebar">
    <header class="fm-sidebar__header">
      <RouterLink class="fm-sidebar__brand" :to="homePath" aria-label="FreshMart workspace home">
        <span class="fm-sidebar__brand-mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none">
            <path d="M5 9h14l-1 11H6L5 9Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
            <path d="M9 9a3 3 0 0 1 6 0M8 13h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          </svg>
        </span>
        <span class="fm-sidebar__brand-copy">
          <strong>{{ sessionStore.state.settings.business_name || 'FreshMart' }}</strong>
          <small>Business System</small>
        </span>
      </RouterLink>
      <UiButton
        class="fm-sidebar__mobile-close"
        variant="ghost"
        icon-only
        aria-label="Close navigation"
        @click="hideMobile(true)"
      >
        <svg class="fm-sidebar__action-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="m6 6 12 12M18 6 6 18" />
        </svg>
      </UiButton>
      <UiButton
        class="fm-sidebar__collapse"
        variant="ghost"
        icon-only
        :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        :aria-expanded="!collapsed"
        :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="toggleCollapsed"
      >
        <svg class="fm-sidebar__action-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path :d="collapsed ? 'm9 6 6 6-6 6' : 'm15 6-6 6 6 6'" />
        </svg>
      </UiButton>
    </header>

    <nav
      id="primary-sidebar-navigation"
      ref="nav"
      class="fm-sidebar__nav"
      aria-label="Primary navigation"
      tabindex="-1"
    >
      <section
        v-for="group in visibleGroups"
        :key="group.label"
        class="fm-sidebar__group"
        :class="group.accent ? `fm-sidebar__group--${group.accent}` : ''"
        :aria-label="group.label"
      >
        <h2 class="fm-sidebar__group-label">{{ group.label }}</h2>
        <ul class="fm-sidebar__list">
          <li v-for="item in group.items" :key="`${group.label}-${item.label}`">
            <RouterLink
              class="fm-sidebar__nav-link"
              :to="item.to"
              :aria-current="isActive(item.to) ? 'page' : undefined"
              :aria-label="collapsed ? item.label : undefined"
              :title="collapsed ? item.label : undefined"
              @click="hideMobile()"
            >
              <svg class="fm-sidebar__nav-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path v-for="path in item.icon" :key="path" :d="path" />
              </svg>
              <span class="fm-sidebar__nav-label">{{ item.label }}</span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </nav>

    <footer class="fm-sidebar__footer">
      <div
        class="fm-sidebar__user"
        tabindex="0"
        :aria-label="`Signed in as ${displayName}, ${username}`"
        :title="collapsed ? `${displayName} · ${username}` : undefined"
      >
        <span class="fm-sidebar__avatar" aria-hidden="true">{{ initials }}</span>
        <span class="fm-sidebar__user-copy">
          <strong>{{ displayName }}</strong>
          <small>@{{ username }}</small>
        </span>
      </div>
      <UiButton
        class="fm-sidebar__logout"
        variant="ghost"
        :icon-only="collapsed"
        :loading="signingOut"
        loading-label="Signing out"
        aria-label="Sign out"
        @click="logout"
      >
        <svg class="fm-sidebar__action-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M10 5H5v14h5M14 8l4 4-4 4m4-4H9" />
        </svg>
        <span class="fm-sidebar__logout-label">Sign out</span>
      </UiButton>
    </footer>
  </div>
</template>
