import { mount } from '@vue/test-utils'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'
import { describe, it, expect } from 'vitest'

describe('TaskStatusBadge', () => {
  it('renders PENDING with correct classes', () => {
    const wrapper = mount(TaskStatusBadge, { props: { status: 'PENDING' } })
    const classes = wrapper.classes()
    expect(classes).toContain('bg-zinc-100')
    expect(wrapper.text()).toBe('Pending')
  })

  it('renders RUNNING with blue classes and pulse dot', () => {
    const wrapper = mount(TaskStatusBadge, { props: { status: 'RUNNING' } })
    expect(wrapper.classes()).toContain('bg-blue-100')
    expect(wrapper.text()).toBe('Running')
    expect(wrapper.find('.animate-pulse').exists()).toBe(true)
  })

  it('renders COMPLETED with green classes', () => {
    const wrapper = mount(TaskStatusBadge, { props: { status: 'COMPLETED' } })
    expect(wrapper.classes()).toContain('bg-green-100')
    expect(wrapper.text()).toBe('Completed')
  })

  it('renders FAILED with red classes', () => {
    const wrapper = mount(TaskStatusBadge, { props: { status: 'FAILED' } })
    expect(wrapper.classes()).toContain('bg-red-100')
    expect(wrapper.text()).toBe('Failed')
  })

  it('renders PENDING_APPROVAL with amber classes and dot', () => {
    const wrapper = mount(TaskStatusBadge, { props: { status: 'PENDING_APPROVAL' } })
    expect(wrapper.classes()).toContain('bg-amber-100')
    expect(wrapper.text()).toBe('Awaiting Approval')
    // dot indicator (not pulse)
    expect(wrapper.find('.bg-amber-500').exists()).toBe(true)
  })
})
