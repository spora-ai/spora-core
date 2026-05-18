import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import MemoryEditor from '@/apps/memories/components/MemoryEditor.vue'

const createMemory = (overrides = {}) => ({
  id: 1,
  user_id: 1,
  agent_id: 3,
  name: 'Test Memory',
  summary: 'A test summary',
  content: 'Test content',
  order: 1,
  created_at: '2025-01-01T00:00:00Z',
  updated_at: '2025-01-01T00:00:00Z',
  ...overrides,
})

describe('MemoryEditor', () => {
  it('renders "New Memory" heading when no memory prop', () => {
    const wrapper = mount(MemoryEditor, { props: {} })
    expect(wrapper.find('h2').text()).toContain('New Memory')
  })

  it('renders "Edit Memory" heading when memory prop is set', () => {
    const wrapper = mount(MemoryEditor, {
      props: { memory: createMemory() },
    })
    expect(wrapper.find('h2').text()).toContain('Edit Memory')
  })

  it('does NOT render order input field', () => {
    const wrapper = mount(MemoryEditor, { props: {} })
    expect(wrapper.find('input[type="number"]').exists()).toBe(false)
  })

  it('renders "New Memory for {name}" when agentName prop is provided in create mode', () => {
    const wrapper = mount(MemoryEditor, {
      props: { agentName: 'My Agent' },
    })
    expect(wrapper.find('h2').text()).toContain('New Memory for My Agent')
  })

  it('renders "Edit Memory for {name}" when agentName prop is provided in edit mode', () => {
    const wrapper = mount(MemoryEditor, {
      props: { memory: createMemory(), agentName: 'My Agent' },
    })
    expect(wrapper.find('h2').text()).toContain('Edit Memory for My Agent')
  })

  it('emits save with correct data on submit', async () => {
    const wrapper = mount(MemoryEditor, {
      props: { onSave: (data: unknown) => {} },
    })
    await wrapper.find('input[type="text"]').setValue('my_memory')
    await wrapper.find('form').trigger('submit')
    const saveEvents = (wrapper.emitted('save') as unknown[][])
    expect(saveEvents.length).toBe(1)
    expect(saveEvents[0][0]).toMatchObject({ name: 'my_memory' })
  })

  it('does not include order in save payload', async () => {
    const wrapper = mount(MemoryEditor, {
      props: { onSave: (data: unknown) => {} },
    })
    await wrapper.find('input[type="text"]').setValue('my_memory')
    await wrapper.find('form').trigger('submit')
    const saveEvents = (wrapper.emitted('save') as unknown[][])
    expect(saveEvents[0][0]).not.toHaveProperty('order')
  })
})
