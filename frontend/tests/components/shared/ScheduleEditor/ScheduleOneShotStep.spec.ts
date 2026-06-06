/**
 * ScheduleOneShotStep — date + time inputs for the one-shot branch.
 *
 * The step owns nothing; it proxies v-model to the parent's `form` refs.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import ScheduleOneShotStep from '@/components/shared/ScheduleEditor/ScheduleOneShotStep.vue'
import type { ScheduleForm } from '@/composables/useScheduleForm'

function makeForm(): ScheduleForm {
  return {
    runDate: ref(''),
    runTime: ref(''),
  } as unknown as ScheduleForm
}

describe('ScheduleOneShotStep', () => {
  it('renders date and time inputs with empty values by default', () => {
    const form = makeForm()
    const wrapper = mount(ScheduleOneShotStep, { props: { form } })
    const date = wrapper.find('#schedule-date')
    const time = wrapper.find('#schedule-time')
    expect(date.exists()).toBe(true)
    expect(time.exists()).toBe(true)
    expect((date.element as HTMLInputElement).value).toBe('')
    expect((time.element as HTMLInputElement).value).toBe('')
  })

  it('reflects the form values into the inputs', () => {
    const form = makeForm()
    form.runDate.value = '2026-08-15'
    form.runTime.value = '14:30'
    const wrapper = mount(ScheduleOneShotStep, { props: { form } })
    expect((wrapper.find('#schedule-date').element as HTMLInputElement).value).toBe('2026-08-15')
    expect((wrapper.find('#schedule-time').element as HTMLInputElement).value).toBe('14:30')
  })

  it('writes input changes back into the form refs', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleOneShotStep, { props: { form } })
    await wrapper.find('#schedule-date').setValue('2026-09-01')
    await wrapper.find('#schedule-time').setValue('08:15')
    expect(form.runDate.value).toBe('2026-09-01')
    expect(form.runTime.value).toBe('08:15')
  })
})
