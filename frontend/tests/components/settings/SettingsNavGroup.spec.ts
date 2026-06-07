import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import SettingsNavGroup from '@/components/settings/SettingsNavGroup.vue'

vi.mock('lucide-vue-next', () => ({
  ChevronRight: { template: '<span data-testid="chevron" />' },
}))

describe('SettingsNavGroup', () => {
  it('renders slot content when no title is provided', () => {
    const wrapper = mount(SettingsNavGroup, {
      slots: { default: '<p>body</p>' },
    })
    expect(wrapper.text()).toContain('body')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('renders the title as a toggle button when provided', () => {
    const wrapper = mount(SettingsNavGroup, {
      props: { title: 'Settings' },
      slots: { default: '<p>body</p>' },
    })
    const button = wrapper.find('button')
    expect(button.exists()).toBe(true)
    expect(button.text()).toContain('Settings')
    expect(button.attributes('aria-expanded')).toBe('true')
  })

  it('is open by default and toggles closed on click', async () => {
    const wrapper = mount(SettingsNavGroup, {
      props: { title: 'Administration' },
      slots: { default: '<p>body</p>' },
    })
    expect(wrapper.text()).toContain('body')
    expect(wrapper.find('button').attributes('aria-expanded')).toBe('true')
    await wrapper.find('button').trigger('click')
    expect(wrapper.find('button').attributes('aria-expanded')).toBe('false')
    // Slot content hidden by v-show
    expect((wrapper.element as HTMLElement).querySelector('p')?.parentElement?.style.display).toBe('none')
  })

  it('respects defaultOpen=false and toggles open on click', async () => {
    const wrapper = mount(SettingsNavGroup, {
      props: { title: 'Administration', defaultOpen: false },
      slots: { default: '<p>body</p>' },
    })
    expect(wrapper.find('button').attributes('aria-expanded')).toBe('false')
    await wrapper.find('button').trigger('click')
    expect(wrapper.find('button').attributes('aria-expanded')).toBe('true')
  })
})
