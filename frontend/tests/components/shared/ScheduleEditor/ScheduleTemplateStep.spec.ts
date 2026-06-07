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
})
