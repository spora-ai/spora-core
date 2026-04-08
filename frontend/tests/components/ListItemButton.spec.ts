import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ListItemButton from '@/components/ui/ListItemButton.vue'

vi.mock('lucide-vue-next', () => ({
  ChevronRight: { template: '<span data-testid="chevron" />' },
}))

describe('ListItemButton', () => {
  it('renders the title', () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Web Search' } })
    expect(wrapper.text()).toContain('Web Search')
  })

  it('renders subtitle when provided', () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Tool', subtitle: '3 settings' } })
    expect(wrapper.text()).toContain('3 settings')
  })

  it('does not render subtitle element when not provided', () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Tool' } })
    // title is in a <p>; subtitle gets a second <p> with text-xs class
    expect(wrapper.find('p.text-xs').exists()).toBe(false)
  })

  it('emits click when clicked', async () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Tool' } })
    await wrapper.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('applies active styles when active=true', () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Tool', active: true } })
    expect(wrapper.classes()).toContain('bg-muted/70')
  })

  it('applies hover styles when active=false', () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Tool', active: false } })
    expect(wrapper.classes()).toContain('hover:bg-muted/50')
  })

  it('renders the chevron icon', () => {
    const wrapper = mount(ListItemButton, { props: { title: 'Tool' } })
    expect(wrapper.find('[data-testid="chevron"]').exists()).toBe(true)
  })
})
