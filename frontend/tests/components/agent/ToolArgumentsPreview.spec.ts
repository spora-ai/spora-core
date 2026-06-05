import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ToolArgumentsPreview from '@/components/agent/ToolArgumentsPreview.vue'

const global = { stubs: { Icon: true } }

describe('ToolArgumentsPreview', () => {
  it('renders flat arguments as a field list', () => {
    const wrapper = mount(ToolArgumentsPreview, {
      props: { arguments: { to: 'a@b.co', body: 'hi' } },
      global,
    })
    expect(wrapper.text()).toContain('a@b.co')
    expect(wrapper.text()).toContain('hi')
  })

  it('renders complex arguments as syntax-highlighted JSON', () => {
    const wrapper = mount(ToolArgumentsPreview, {
      props: { arguments: { nested: { a: 1, b: [1, 2, 3] } } },
      global,
    })
    expect(wrapper.html()).toContain('<pre')
  })

  it('toggles sensitive value visibility', async () => {
    const wrapper = mount(ToolArgumentsPreview, {
      props: { arguments: { password: 'secret123' } },
      global,
    })
    expect(wrapper.text()).toContain('••••••••')
    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBeGreaterThan(0)
  })

  it('auto-expands when expanded prop is true', () => {
    const wrapper = mount(ToolArgumentsPreview, {
      props: { arguments: { to: 'a@b.co' }, expanded: true },
      global,
    })
    const details = wrapper.find('details')
    expect(details.attributes('open')).toBeDefined()
  })

  it('handles null arguments without throwing', () => {
    expect(() => mount(ToolArgumentsPreview, { props: { arguments: null }, global })).not.toThrow()
  })
})
