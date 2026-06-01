import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Provides isAdmin and isForbidden flags based on the current user's admin status.
 */
export function useAdminAuth() {
  const auth = useAuthStore()
  const isAdmin = computed(() => auth.user?.is_admin ?? false)
  const isForbidden = computed(() => !auth.user || !auth.user.is_admin)
  return { isAdmin, isForbidden }
}