import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach } from 'vitest'
import ScheduleTypeStep from '@/components/shared/ScheduleEditor/ScheduleTypeStep.vue'
import { useScheduleForm } from '@/composables/useScheduleForm'
import type { ScheduleForm } from '@/composables/useScheduleForm'

beforeEach(() => {
  setActivePinia(createPinia())
})

function makeForm(): ScheduleForm {
  return useScheduleForm()
}

describe('ScheduleTypeStep', () => {
  it('renders both mode buttons', () => {
    const form = makeForm()
    const wrapper = mount(ScheduleTypeStep, { props: { form } })
    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBe(2)
    expect(buttons[0].text()).toBe('One-shot')
    expect(buttons[1].text()).toBe('Recurring')
  })

  it('clicking the recurring button updates form.mode', async () => {
    const form = makeForm()
    form.mode.value = 'oneshot'
    const wrapper = mount(ScheduleTypeStep, { props: { form } })
    const buttons = wrapper.findAll('button')
    await buttons[1].trigger('click')
    expect(form.mode.value).toBe('recurring')
  })
})
