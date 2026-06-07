/**
 * LLMConfigList — list of LLM configurations with preferred/global badges.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const configsRef = ref<Array<{ id: number; name: string; driver_class: string; driver_display_name: string; is_default: boolean; is_global: boolean }>>([])
const personalConfigsRef = ref<Array<{ id: number; name: string; driver_class: string; driver_display_name: string; is_default: boolean; is_global: boolean }>>([])
const preferenceRef = ref<{ config: { id: number } } | null>(null)
const userRef = ref<{ is_admin: boolean } | null>(null)

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({
    get configs() { return configsRef.value },
    get personalConfigs() { return personalConfigsRef.value },
  }),
}))

vi.mock('@/stores/llmPreferencesStore', () => ({
  useLlmPreferencesStore: () => ({
    get preference() { return preferenceRef.value },
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() { return userRef.value },
  }),
}))

import LLMConfigList from '@/components/settings/llm/LLMConfigList.vue'

const sampleConfig = (overrides: Partial<{ id: number; name: string; driver_class: string; driver_display_name: string; is_default: boolean; is_global: boolean }> = {}) => ({
  id: 1, name: 'My OpenAI', driver_class: 'OpenAI', driver_display_name: 'OpenAI', is_default: false, is_global: false, ...overrides,
})

function setConfigs(items: Array<{ id: number; name: string; is_default: boolean; is_global: boolean }>): void {
  configsRef.value = items
  personalConfigsRef.value = items
}

beforeEach(() => {
  setActivePinia(createPinia())
  configsRef.value = []
  personalConfigsRef.value = []
  preferenceRef.value = null
  userRef.value = null
})

describe('LLMConfigList', () => {
  it('shows empty state when no configs', () => {
    const wrapper = mount(LLMConfigList)
    expect(wrapper.text()).toContain('No LLM configurations yet.')
    expect(wrapper.text()).toContain('Create your first configuration')
  })

  it('emits create when empty-state button is clicked', async () => {
    const wrapper = mount(LLMConfigList)
    const btn = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Create your first configuration'))
    expect(btn).toBeDefined()
    await btn!.trigger('click')
    expect(wrapper.emitted('create')).toBeTruthy()
  })

  it('renders the list of personal configs', () => {
    setConfigs([sampleConfig({ id: 1, name: 'cfg-a' }), sampleConfig({ id: 2, name: 'cfg-b' })])
    const wrapper = mount(LLMConfigList)
    expect(wrapper.text()).toContain('cfg-a')
    expect(wrapper.text()).toContain('cfg-b')
    expect(wrapper.text()).toContain('+ Add New')
  })

  it('emits create when the "Add New" button is clicked', async () => {
    setConfigs([sampleConfig()])
    const wrapper = mount(LLMConfigList)
    const btn = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Add New'))
    expect(btn).toBeDefined()
    await btn!.trigger('click')
    expect(wrapper.emitted('create')).toBeTruthy()
  })

  it('emits select with the clicked config', async () => {
    setConfigs([sampleConfig({ id: 7, name: 'pick-me' })])
    const wrapper = mount(LLMConfigList)
    const row = wrapper.findAll("div").find((d) => d.classes().includes("cursor-pointer") && d.text().includes("pick-me"))
    expect(row).toBeDefined()
    await row!.trigger('click')
    expect(wrapper.emitted('select')).toBeTruthy()
    expect(wrapper.emitted('select')![0][0]).toMatchObject({ id: 7 })
  })

  it('shows the "Preferred" badge for the user-preferred config', () => {
    setConfigs([sampleConfig({ id: 5, name: 'pref' })])
    preferenceRef.value = { config: { id: 5 } }
    const wrapper = mount(LLMConfigList)
    expect(wrapper.text()).toContain('Preferred')
  })

  it('shows the "Global Default" badge when global and default and not preferred', () => {
    setConfigs([sampleConfig({ id: 6, name: 'gdef', is_global: true, is_default: true })])
    const wrapper = mount(LLMConfigList)
    expect(wrapper.text()).toContain('Global Default')
  })

  it('shows the "Global" badge when global-only and not default and not preferred', () => {
    userRef.value = { is_admin: true }
    setConfigs([sampleConfig({ id: 8, name: 'g', is_global: true, is_default: false })])
    const wrapper = mount(LLMConfigList)
    expect(wrapper.text()).toContain('Global')
  })
})
