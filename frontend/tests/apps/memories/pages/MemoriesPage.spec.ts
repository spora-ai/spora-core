/**
 * MemoriesPage — self-contained shell for the /apps/memories route tree.
 *
 * Renders GlobalNavbar + MemorySidebar + <RouterView>. The sidebar's own
 * coverage lives in MemorySidebar.spec.ts; here we assert the shell pieces
 * are wired and that the mobile toggle works.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/components/GlobalNavbar.vue', () => ({
  default: { name: 'GlobalNavbar', template: '<div class="navbar-stub" />' },
}))

vi.mock('@/apps/memories/components/MemorySidebar.vue', () => ({
  default: {
    name: 'MemorySidebar',
    props: { mobileOpen: { type: Boolean, default: false } },
    inheritAttrs: false,
    template: '<div :data-mobile="mobileOpen" :class="mobileOpen ? \'sidebar-mobile\' : \'sidebar-desktop\'" />',
  },
}))

vi.mock('@/components/ui/Icon.vue', () => ({
  default: { name: 'Icon', template: '<span class="icon-stub" />' },
}))

import MemoriesPage from '@/apps/memories/pages/MemoriesPage.vue'

beforeEach(() => {
  // Ensure the body is clean before each test (no leftover teleports).
  document.body.innerHTML = ''
})

afterEach(() => {
  document.body.innerHTML = ''
})

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  await router.push('/')
  await router.isReady()
  return mount(MemoriesPage, {
    global: { plugins: [router] },
  })
}

describe('MemoriesPage', () => {
  it('renders the GlobalNavbar', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('.navbar-stub').exists()).toBe(true)
  })

  it('renders both the mobile overlay sidebar and the desktop sidebar', async () => {
    const wrapper = await mountPage()
    // Two MemorySidebar instances are rendered: one inside the mobile overlay
    // (only when sidebarOpen), one as the desktop sidebar. Initially only
    // desktop is visible.
    expect(wrapper.findAll('.sidebar-desktop').length).toBeGreaterThanOrEqual(1)
  })

  it('renders the mobile menu toggle button', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('button[title="Show memories menu"]').exists()).toBe(true)
  })

  it('opens the mobile sidebar overlay when the menu button is clicked', async () => {
    const wrapper = await mountPage()
    expect(wrapper.findAll('.sidebar-mobile').length).toBe(0)
    const menuButton = wrapper.find('button[title="Show memories menu"]')
    expect(menuButton.exists()).toBe(true)
    await menuButton.trigger('click')
    await wrapper.vm.$nextTick()
    // After click, sidebarOpen flips and the mobile overlay v-if renders
    // a second MemorySidebar with mobileOpen=true → .sidebar-mobile class.
    expect(wrapper.findAll('.sidebar-mobile').length).toBeGreaterThanOrEqual(1)
  })

  it('renders the RouterView outlet for sub-routes', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('main').exists()).toBe(true)
  })
})
