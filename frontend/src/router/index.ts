import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/LoginPage.vue'),
      meta: { requiresGuest: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/pages/RegisterPage.vue'),
      meta: { requiresGuest: true },
    },
    {
      path: '/',
      name: 'dashboard',
      component: () => import('@/pages/DashboardPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/agents/:id',
      name: 'agent',
      component: () => import('@/pages/AgentPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/agents/:id/settings',
      name: 'agent-settings',
      component: () => import('@/pages/AgentSettingsPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('@/pages/GlobalSettingsPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/tasks/:id',
      name: 'task',
      component: () => import('@/pages/TaskChatPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.initialized) {
    await auth.init()
  }

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login' }
  }

  if (to.meta.requiresGuest && auth.user) {
    return { name: 'dashboard' }
  }
})

export default router
