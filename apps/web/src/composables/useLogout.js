import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { sessionStore } from '../stores/session.js'
import { useSidebarState } from './useSidebarState.js'

const signingOut = ref(false)

export function useLogout() {
  const router = useRouter()
  const { closeMobile } = useSidebarState()

  async function logout() {
    if (signingOut.value) return

    signingOut.value = true
    try {
      await sessionStore.logout()
      closeMobile()
      await router.replace('/login')
    } finally {
      signingOut.value = false
    }
  }

  return {
    logout,
    signingOut
  }
}
