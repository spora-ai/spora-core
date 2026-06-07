/**
 * GlobalMemoriesPage — global memory CRUD with drag-to-reorder.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const routeRef = ref({ query: {} as Record<string, string> })
vi.mock('vue-router', () => ({
  useRoute: () => routeRef.value,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

const globalMemories = ref<Array<{ id: number; name: string; content: string; order: number }>>([])
const loadGlobalMemories = vi.fn()
const createGlobalMemory = vi.fn()
const updateGlobalMemory = vi.fn()
const deleteGlobalMemory = vi.fn()
const reorderGlobalMemories = vi.fn()

vi.mock('@/apps/memories/stores/memories', () => ({
  useMemoriesStore: () => ({
    get globalMemories() { return globalMemories.value },
    agentMemories: [],
    loadingGlobal: false,
    loadingAgent: false,
    saving: false,
    error: null,
    loadGlobalMemories,
    createGlobalMemory,
    updateGlobalMemory,
    deleteGlobalMemory,
    reorderGlobalMemories,
  }),
}))

const MemoryListItemStub = { name: 'MemoryListItem', template: '<div class="list-item-stub" />' }
const MemoryEditorStub = { name: 'MemoryEditor', template: '<div class="editor-stub" />' }
const VueDraggableStub = { name: 'VueDraggable', template: '<div class="draggable-stub"><slot /></div>' }

import GlobalMemoriesPage from '@/apps/memories/pages/GlobalMemoriesPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  globalMemories.value = []
  loadGlobalMemories.mockReset()
  loadGlobalMemories.mockResolvedValue(undefined)
  routeRef.value = { query: {} }
})

describe('GlobalMemoriesPage', () => {
  it('mounts and calls loadGlobalMemories on mount', async () => {
    const wrapper = mount(GlobalMemoriesPage, {
      global: {
        stubs: {
          MemoryListItem: MemoryListItemStub,
          MemoryEditor: MemoryEditorStub,
          VueDraggable: VueDraggableStub,
        },
      },
    })
    await flushPromises()
    expect(loadGlobalMemories).toHaveBeenCalled()
    expect(wrapper.exists()).toBe(true)
  })

  it('renders an empty state when there are no memories', async () => {
    const wrapper = mount(GlobalMemoriesPage, {
      global: {
        stubs: {
          MemoryListItem: MemoryListItemStub,
          MemoryEditor: MemoryEditorStub,
          VueDraggable: VueDraggableStub,
        },
      },
    })
    await flushPromises()
    expect(wrapper.text()).toMatch(/memories|empty|create|get started/i)
  })

  it('switches to create view when ?create=1 is in the URL', async () => {
    routeRef.value = { query: { create: '1' } }
    const wrapper = mount(GlobalMemoriesPage, {
      global: {
        stubs: {
          MemoryListItem: MemoryListItemStub,
          MemoryEditor: MemoryEditorStub,
          VueDraggable: VueDraggableStub,
        },
      },
    })
    await flushPromises()
    expect(wrapper.find('.editor-stub').exists()).toBe(true)
  })
})
