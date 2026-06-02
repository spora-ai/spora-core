import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import TaskFailedBanner from '@/components/agent/TaskFailedBanner.vue'

describe('TaskFailedBanner', () => {
  it('pluralizes "steps" correctly for 0 / 1 / 2+ steps', () => {
    expect(mount(TaskFailedBanner, { props: { stepCount: 0 } }).text()).toBe('Task failed after 0 steps.')
    expect(mount(TaskFailedBanner, { props: { stepCount: 1 } }).text()).toBe('Task failed after 1 step.')
    expect(mount(TaskFailedBanner, { props: { stepCount: 2 } }).text()).toBe('Task failed after 2 steps.')
    expect(mount(TaskFailedBanner, { props: { stepCount: 17 } }).text()).toBe('Task failed after 17 steps.')
  })
})
