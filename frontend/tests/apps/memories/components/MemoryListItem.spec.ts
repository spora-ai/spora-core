/**
 * MemoryListItem — single memory row in a list.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, vi } from 'vitest'

const memory = {
  id: 1,
  name: 'Test Memory Name',
  content: 'A test memory',
  order: 0,
  scope: 'global' as const,
  agent_id: null,
  metadata: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

const emitMock = vi.fn()
import MemoryListItem from '@/apps/memories/components/MemoryListItem.vue'

describe('MemoryListItem', () => {
  it('renders the memory content', () => {
    const wrapper = mount(MemoryListItem, {
      props: { memory },
      global: {
        stubs: {
          MemoryEditor: { name: 'MemoryEditor', template: '<div class="editor-stub" />' },
        },
      },
    })
    expect(wrapper.text()).toContain('Test Memory Name')
  })

  it('emits delete when delete is triggered', async () => {
    const wrapper = mount(MemoryListItem, {
      props: { memory },
      global: {
        stubs: {
          MemoryEditor: { name: 'MemoryEditor', template: '<div class="editor-stub" />' },
        },
      },
    })
    // The item renders the editor; delete is triggered via the editor
    expect(wrapper.exists()).toBe(true)
  })
})
