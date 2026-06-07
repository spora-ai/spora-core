import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach } from 'vitest'
import ScheduleStepper from '@/components/shared/ScheduleEditor/ScheduleStepper.vue'
import { useScheduleForm } from '@/composables/useScheduleForm'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ScheduleStepper', () => {
  it('renders three step labels', () => {
    const form = useScheduleForm()
    const wrapper = mount(ScheduleStepper, {
      props: { form },
      global: { stubs: { Icon: true } },
    })
    expect(wrapper.text()).toContain('Template')
    expect(wrapper.text()).toContain('Schedule Type')
    expect(wrapper.text()).toContain('Schedule')
  })
})
