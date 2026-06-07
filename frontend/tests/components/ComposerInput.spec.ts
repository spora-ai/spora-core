/**
 * ComposerInput — prompt composer with template selection, scheduling, and
 * task submission. Covers the schedule button, save-as-template, template
 * selection, agent info, submit error, and the template error paths.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

// vi.hoisted: variables available inside vi.mock factories (which are
// themselves hoisted to the top of the file).
const {
  routerPushMock,
  confirmMock,
  createTaskForAgentMock,
  clearComposerDraftMock,
  deleteTemplateMock,
  fetchAllTemplatesMock,
  apiMock,
} = vi.hoisted(() => {
  const routerPushMock = vi.fn()
  const confirmMock = vi.fn().mockResolvedValue(true)
  const createTaskForAgentMock = vi.fn().mockResolvedValue({ id: 99 })
  const clearComposerDraftMock = vi.fn()
  const deleteTemplateMock = vi.fn()
  const fetchAllTemplatesMock = vi.fn()
  const apiMock = { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }
  return {
    routerPushMock,
    confirmMock,
    createTaskForAgentMock,
    clearComposerDraftMock,
    deleteTemplateMock,
    fetchAllTemplatesMock,
    apiMock,
  }
})

// Top-level reactive state (mock factories are lazy; they run when stores
// are called, by which time the refs are initialised).
const currentAgentRef = ref<{ id: number; name: string; llm_driver_config_id: number | null; max_steps: number; tools: { name: string }[] } | null>({
  id: 1,
  name: 'Test Agent',
  llm_driver_config_id: 1,
  max_steps: 5,
  tools: [{ name: 'web_search' }, { name: 'calculator' }, { name: 'send_email' }],
})
const llmConfigsRef = ref<Array<{ id: number; name: string }>>([
  { id: 1, name: 'GPT-4' },
  { id: 2, name: 'Claude' },
])
const preferenceRef = ref<null | { config: { name: string } }>(null)
const promptTemplatesRef = ref<Array<{ id: number; name: string; prompt_template: string; variables: string[] }>>([])
// The composer draft: the real store mutates a reactive object, so the
// mock exposes a getter/setter on a ref to preserve reactivity.
const draftTextRef = ref('')

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: routerPushMock, replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    get currentAgent() { return currentAgentRef.value },
    getComposerDraft: () => ({
      get promptText() { return draftTextRef.value },
      set promptText(v: string) { draftTextRef.value = v },
    }),
    clearComposerDraft: clearComposerDraftMock,
  }),
}))

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({
    get configs() { return llmConfigsRef.value },
  }),
}))

vi.mock('@/stores/llmPreferencesStore', () => ({
  useLlmPreferencesStore: () => ({
    get preference() { return preferenceRef.value },
  }),
}))

vi.mock('@/stores/promptTemplates', () => ({
  usePromptTemplatesStore: () => ({
    get templates() { return promptTemplatesRef.value },
    fetchAll: fetchAllTemplatesMock,
    deleteTemplate: deleteTemplateMock,
  }),
}))

vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => ({
    createTaskForAgent: createTaskForAgentMock,
  }),
}))

vi.mock('@/composables/useConfirmDialog', () => ({
  useConfirmDialog: () => ({ confirm: confirmMock }),
}))

vi.mock('@/composables/useComposerInput', () => ({
  isSubmitKeystroke: (e: KeyboardEvent) => (e.metaKey || e.ctrlKey) && e.key === 'Enter',
  buildPromptFromTemplate: (template: string) => `rendered:${template}`,
}))

vi.mock('@/api/client', () => ({
  default: apiMock,
  api: apiMock,
  ApiError: class ApiError extends Error { status = 0 },
}))

vi.mock('@/components/shared/ScheduleEditor/index.vue', () => ({
  default: { name: 'SharedScheduleEditor', props: ['modelValue', 'agentId', 'initialData'], emits: ['update:modelValue', 'saved', 'closed'], template: '<div v-if="modelValue" data-testid="schedule-editor" />' },
}))

vi.mock('@/components/PromptTemplateDialog.vue', () => ({
  default: { name: 'PromptTemplateDialog', props: ['modelValue', 'agentId', 'initialPrompt', 'existingTemplateId'], emits: ['update:modelValue', 'saved'], template: '<div v-if="modelValue" data-testid="template-dialog" />' },
}))

const IconStub = {
  name: 'Icon',
  props: ['name'],
  template: '<span class="icon-stub" :data-name="name" />',
}

import ComposerInput from '@/components/ComposerInput.vue'

const findSubmitButton = (wrapper: ReturnType<typeof mount>) =>
  wrapper.findAll('button').find((b) => b.classes().includes('rounded-full') && b.classes().includes('bg-primary'))!

const findByText = (wrapper: ReturnType<typeof mount>, text: string) =>
  wrapper.findAll('button').find((b) => b.text().trim() === text)!

beforeEach(() => {
  setActivePinia(createPinia())
  currentAgentRef.value = {
    id: 1,
    name: 'Test Agent',
    llm_driver_config_id: 1,
    max_steps: 5,
    tools: [{ name: 'web_search' }, { name: 'calculator' }, { name: 'send_email' }],
  }
  llmConfigsRef.value = [
    { id: 1, name: 'GPT-4' },
    { id: 2, name: 'Claude' },
  ]
  preferenceRef.value = null
  promptTemplatesRef.value = []
  draftTextRef.value = ''
  routerPushMock.mockReset()
  confirmMock.mockReset()
  confirmMock.mockResolvedValue(true)
  createTaskForAgentMock.mockReset()
  createTaskForAgentMock.mockResolvedValue({ id: 99 })
  clearComposerDraftMock.mockReset()
  deleteTemplateMock.mockReset()
  fetchAllTemplatesMock.mockReset()
})

describe('ComposerInput', () => {
  it('renders a textarea with the default placeholder', () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.find('textarea').exists()).toBe(true)
    expect(wrapper.find('textarea').attributes('placeholder')).toBeTruthy()
  })

  it('renders the current LLM config name from the configs store', () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.text()).toContain('GPT-4')
  })

  it('falls back to "Custom LLM config" when no config matches', () => {
    currentAgentRef.value = { ...currentAgentRef.value!, llm_driver_config_id: 999 }
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.text()).toContain('Custom LLM config')
  })

  it('renders the preferred config name when no agent config is set', () => {
    currentAgentRef.value = { ...currentAgentRef.value!, llm_driver_config_id: null }
    preferenceRef.value = { config: { name: 'Claude-Opus' } }
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.text()).toContain('Claude-Opus')
    expect(wrapper.text()).toContain('(preferred)')
  })

  it('renders "Global default" when neither agent nor preference is set', () => {
    currentAgentRef.value = { ...currentAgentRef.value!, llm_driver_config_id: null }
    preferenceRef.value = null
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.text()).toContain('Global default')
  })

  it('renders the tool count and max-steps in the agent info row', () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.text()).toContain('3 tools')
    expect(wrapper.text()).toContain('Max 5 steps')
  })

  it('does not render the agent info row when there is no current agent', () => {
    currentAgentRef.value = null
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.text()).not.toContain('Max 5 steps')
  })

  it('disables the submit button when the prompt is empty', () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    const submitBtn = findSubmitButton(wrapper)
    expect(submitBtn.attributes('disabled')).toBeDefined()
  })

  it('submits a task when the user clicks the send button', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('hello')
    const submitBtn = findSubmitButton(wrapper)
    await submitBtn.trigger('click')
    await flushPromises()
    expect(createTaskForAgentMock).toHaveBeenCalledWith(1, 'hello')
    expect(clearComposerDraftMock).toHaveBeenCalledWith(1)
    expect(routerPushMock).toHaveBeenCalledWith({ name: 'task', params: { id: 99 } })
  })

  it('submits a task on Cmd+Enter / Ctrl+Enter', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('via shortcut')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter', metaKey: true })
    await flushPromises()
    expect(createTaskForAgentMock).toHaveBeenCalledWith(1, 'via shortcut')
  })

  it('does not submit on plain Enter (no modifier)', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('test')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(createTaskForAgentMock).not.toHaveBeenCalled()
  })

  it('does not submit when the prompt is empty after trim', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('   ')
    const submitBtn = findSubmitButton(wrapper)
    await submitBtn.trigger('click')
    await flushPromises()
    expect(createTaskForAgentMock).not.toHaveBeenCalled()
  })


  it('surfaces a submit error when the task creation fails', async () => {
    createTaskForAgentMock.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('will fail')
    const submitBtn = findSubmitButton(wrapper)
    await submitBtn.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Failed to start task.')
  })

  it('opens the schedule editor when the Schedule button is clicked', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    const scheduleBtn = findByText(wrapper, 'Schedule')
    await scheduleBtn.trigger('click')
    await nextTick()
    expect(wrapper.find('[data-testid="schedule-editor"]').exists()).toBe(true)
  })

  it('renders the template selector when templates exist', () => {
    promptTemplatesRef.value = [
      { id: 1, name: 'Greet', prompt_template: 'Hello', variables: [] },
    ]
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    expect(wrapper.find('select').exists()).toBe(true)
    expect(wrapper.text()).toContain('Greet')
  })

  it('selecting a template sets the prompt text', async () => {
    promptTemplatesRef.value = [
      { id: 1, name: 'Greet', prompt_template: 'Hello {{name}}', variables: ['name'] },
    ]
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    const select = wrapper.find('select')
    await select.setValue('1')
    await flushPromises()
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('rendered:Hello {{name}}')
  })

  it('deleting the selected template confirms and calls deleteTemplate', async () => {
    promptTemplatesRef.value = [
      { id: 1, name: 'Greet', prompt_template: 'Hello', variables: [] },
    ]
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('select').setValue('1')
    await flushPromises()
    const deleteBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Delete template')!
    await deleteBtn.trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(deleteTemplateMock).toHaveBeenCalledWith(1, 1)
  })

  it('shows the Save-as-template button when there is prompt text', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('Save this prompt')
    await flushPromises()
    const saveBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Save prompt as template')
    expect(saveBtn).toBeDefined()
  })

  it('disables the textarea while submitting', async () => {
    let resolveCreate: (v: { id: number }) => void
    createTaskForAgentMock.mockReturnValue(new Promise((r) => { resolveCreate = r }))
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('go')
    const submitBtn = findSubmitButton(wrapper)
    await submitBtn.trigger('click')
    await nextTick()
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).disabled).toBe(true)
    resolveCreate!({ id: 1 })
    await flushPromises()
  })

  it('respects the `disabled` prop', () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1, disabled: true },
      global: { stubs: { Icon: IconStub } },
    })
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).disabled).toBe(true)
  })

  it('clicks the agent info buttons and pushes the agent-settings route', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 42 },
      global: { stubs: { Icon: IconStub } },
    })
        const settingsBtns = wrapper.findAll('button').filter((b) => b.attributes('title') === 'Go to agent settings')
    const toolsBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Go to agent tools')!
    await settingsBtns[0].trigger('click')
    await toolsBtn.trigger('click')
    await settingsBtns[1].trigger('click')
    expect(routerPushMock).toHaveBeenCalledTimes(3)
    expect(routerPushMock).toHaveBeenCalledWith({ name: 'agent-settings', params: { id: 42 } })
  })

  it('opens the save-as-template dialog when the Save button is clicked', async () => {
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('save me')
    await flushPromises()
    const saveBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Save prompt as template')!
    await saveBtn.trigger('click')
    await nextTick()
        expect(wrapper.find('[data-testid="template-dialog"]').exists()).toBe(true)
  })

  it('fires the SharedScheduleEditor @saved event to call onScheduleSaved', async () => {
    const SharedScheduleEditor = (await import('@/components/shared/ScheduleEditor/index.vue')).default
    const wrapper = mount(ComposerInput, {
      props: { agentId: 1 },
      global: { stubs: { Icon: IconStub } },
    })
    await wrapper.find('textarea').setValue('to schedule')
    await flushPromises()
        const scheduleBtn = findByText(wrapper, 'Schedule')
    await scheduleBtn.trigger('click')
    await nextTick()
        const editor = wrapper.findComponent(SharedScheduleEditor)
    expect(editor.exists()).toBe(true)
    editor.vm.$emit('saved')
    await flushPromises()
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('')
  })
})
