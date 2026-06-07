import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach } from 'vitest'
import ScheduleTemplateStep from '@/components/shared/ScheduleEditor/ScheduleTemplateStep.vue'
import { useScheduleForm } from '@/composables/useScheduleForm'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ScheduleTemplateStep', () => {
  it('renders the template select and prompt textarea', () => {
    const form = useScheduleForm()
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.find('select#schedule-template').exists()).toBe(true)
    expect(wrapper.find('textarea#schedule-prompt').exists()).toBe(true)
    expect(wrapper.text()).toContain('Choose an existing prompt template')
  })

  it('shows the new-template name input when templateId is -1', () => {
    const form = useScheduleForm()
    form.templateId.value = -1
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.find('input[placeholder*="Template name"]').exists()).toBe(true)
  })

  it('hides the new-template name input when templateId is null', () => {
    const form = useScheduleForm()
    form.templateId.value = null
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.find('input[placeholder*="Template name"]').exists()).toBe(false)
  })

  it('disables the prompt textarea when a real template is selected', () => {
    const form = useScheduleForm()
    form.templateId.value = 42
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.find('textarea#schedule-prompt').attributes('disabled')).toBeDefined()
  })

  it('renders the templates from the promptTemplatesStore as <option>s', async () => {
    const { usePromptTemplatesStore } = await import('@/stores/promptTemplates')
    const store = usePromptTemplatesStore()
    store.templates = [
      { id: 1, name: 'Daily Digest', prompt_template: 'digest' },
      { id: 2, name: 'Weekly Review', prompt_template: 'review' },
    ]
    const form = useScheduleForm()
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    const options = wrapper.findAll('select#schedule-template option')
    // — None —, two templates, + Create new template…
    expect(options).toHaveLength(4)
    expect(wrapper.text()).toContain('Daily Digest')
    expect(wrapper.text()).toContain('Weekly Review')
  })

  it('writes rawPrompt textarea changes back to form.rawPrompt', async () => {
    const form = useScheduleForm()
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    await wrapper.find('textarea#schedule-prompt').setValue('hello {{ now }}')
    expect(form.rawPrompt.value).toBe('hello {{ now }}')
  })

  it('shows the "from template" badge and disables the textarea when templateId is a real id', () => {
    const form = useScheduleForm()
    form.templateId.value = 7
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.text()).toContain('from template')
    expect(wrapper.find('textarea#schedule-prompt').attributes('disabled')).toBeDefined()
  })

  it('writes newTemplateName changes back to form.newTemplateName', async () => {
    const form = useScheduleForm()
    form.templateId.value = -1
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    const input = wrapper.find('input[placeholder*="Template name"]')
    await input.setValue('New one')
    expect(form.newTemplateName.value).toBe('New one')
  })

  it('renders the available runtime variables from the wizard', () => {
    const form = useScheduleForm()
    const wrapper = mount(ScheduleTemplateStep, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.text()).toContain('Available runtime variables')
    // wrapPromptVariable('now') returns something like '{{ now }}' — assert it is rendered
    expect(wrapper.text()).toMatch(/\{\{.*\}\}/)
  })
})
