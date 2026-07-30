<script setup>
import { computed } from 'vue'
import { useLogout } from '../../composables/useLogout.js'
import { sessionStore } from '../../stores/session.js'
import UiDropdownMenu from '../ui/UiDropdownMenu.vue'

const { logout, signingOut } = useLogout()

const accessLabels = {
  admin: 'Administration',
  pos: 'Point of Sale',
  hr: 'Human Resources',
  finance: 'Finance',
  employee: 'Employee',
  inventory: 'Inventory',
  dashboard: 'General access'
}

const displayName = computed(() =>
  sessionStore.state.user?.fullName
    || sessionStore.state.user?.username
    || 'FreshMart user'
)
const username = computed(() => sessionStore.state.user?.username || 'Signed in')
const roleLabel = computed(() =>
  accessLabels[sessionStore.state.landingPage] || accessLabels.dashboard
)
const initials = computed(() => displayName.value
  .split(/\s+/)
  .filter(Boolean)
  .slice(0, 2)
  .map(part => part[0]?.toUpperCase())
  .join('') || 'FM')
const userMenuItems = computed(() => [
  {
    key: 'identity',
    label: `${displayName.value}, ${roleLabel.value}`,
    disabled: true
  },
  {
    key: 'logout',
    label: signingOut.value ? 'Signing out' : 'Sign out',
    destructive: true,
    separatorBefore: true,
    disabled: signingOut.value
  }
])

function onMenuSelect(item) {
  if (item.key === 'logout') logout()
}
</script>

<template>
  <div class="fm-topbar">
    <UiDropdownMenu
      class="fm-topbar__user-menu"
      label="Current user menu"
      :items="userMenuItems"
      :disabled="signingOut"
      @select="onMenuSelect"
    >
      <template #trigger>
        <span class="fm-topbar__avatar" aria-hidden="true">{{ initials }}</span>
        <span class="fm-topbar__user-copy">
          <strong>{{ displayName }}</strong>
          <small>{{ roleLabel }}</small>
        </span>
        <svg class="fm-topbar__chevron" viewBox="0 0 24 24" aria-hidden="true">
          <path d="m8 10 4 4 4-4" />
        </svg>
      </template>

      <template #item="{ item }">
        <span v-if="item.key === 'identity'" class="fm-topbar__menu-identity">
          <strong>{{ displayName }}</strong>
          <small>{{ roleLabel }} · @{{ username }}</small>
        </span>
        <span v-else class="fm-topbar__menu-action">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M10 5H5v14h5M14 8l4 4-4 4m4-4H9" />
          </svg>
          {{ item.label }}
        </span>
      </template>
    </UiDropdownMenu>
  </div>
</template>
