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
      path: '/account',
      name: 'account',
      component: () => import('@/pages/AccountPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/agents/:id',
      name: 'agent',
      component: () => import('@/pages/AgentPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/agents/:id/scheduled-runs',
      name: 'scheduled-runs',
      component: () => import('@/pages/ScheduledRunsPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/agents/:id/settings',
      name: 'agent-settings',
      component: () => import('@/pages/AgentSettingsPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('@/pages/ProfileSettingsPage.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/settings',
      component: () => import('@/pages/settings/GlobalSettingsLayout.vue'),
      meta: { requiresAuth: true },
      redirect: '/settings/overview',
      children: [
        {
          path: 'overview',
          name: 'settings-overview',
          component: () => import('@/pages/settings/SettingsOverviewPage.vue'),
        },
        {
          path: 'tools',
          name: 'settings-tools',
          component: () => import('@/pages/settings/SettingsToolsPage.vue'),
        },
        {
          path: 'llm',
          name: 'settings-llm',
          component: () => import('@/pages/settings/SettingsLLMPage.vue'),
        },
        // Admin-only children
        {
          path: 'admin/users',
          name: 'settings-admin-users',
          component: () => import('@/pages/admin/UsersPage.vue'),
        },
        {
          path: 'admin/drivers',
          name: 'settings-admin-drivers',
          component: () => import('@/pages/admin/DriversSettingsPage.vue'),
        },
        {
          path: 'admin/tools',
          name: 'settings-admin-tools',
          component: () => import('@/pages/admin/ToolsSettingsPage.vue'),
        },
        {
          path: 'admin/mail-templates',
          name: 'settings-admin-mail-templates',
          component: () => import('@/pages/admin/MailTemplatesPage.vue'),
        },
      ],
    },
    {
      path: '/tasks/:id',
      name: 'task',
      component: () => import('@/pages/TaskChatPage.vue'),
      meta: { requiresAuth: true },
    },

    // Apps
    {
      path: '/apps',
      component: () => import('@/components/layout/AppsLayout.vue'),
      meta: { requiresAuth: true },
      redirect: '/apps/memories',
      children: [
        {
          path: 'memories',
          name: 'global-memories',
          component: () => import('@/apps/memories/pages/GlobalMemoriesPage.vue'),
        },
        {
          path: 'memories/agents/:id?',
          name: 'agent-memories',
          component: () => import('@/apps/memories/pages/AgentMemoriesPage.vue'),
        },
        {
          path: 'memories/agents/:id/:memoryId',
          name: 'agent-memory-edit',
          component: () => import('@/apps/memories/pages/AgentMemoriesPage.vue'),
        },
      ],
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
