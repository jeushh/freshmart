import { readonly, ref } from 'vue'

const storageKey = 'freshmart:sidebar-collapsed'

function storedCollapsedState() {
  try {
    return sessionStorage.getItem(storageKey) === 'true'
  } catch {
    return false
  }
}

const collapsed = ref(storedCollapsedState())
const mobileOpen = ref(false)

function toggleCollapsed() {
  collapsed.value = !collapsed.value
  try {
    sessionStorage.setItem(storageKey, String(collapsed.value))
  } catch {
    // The visual state still works when browser storage is unavailable.
  }
}

function openMobile() {
  mobileOpen.value = true
}

function closeMobile() {
  mobileOpen.value = false
}

export function useSidebarState() {
  return {
    collapsed: readonly(collapsed),
    mobileOpen: readonly(mobileOpen),
    toggleCollapsed,
    openMobile,
    closeMobile
  }
}
