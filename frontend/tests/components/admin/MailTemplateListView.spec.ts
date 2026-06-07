/**
 * MailTemplateListView — renders a list of mail templates with a system
 * badge and emits `select` on click. The store-side actions (select,
 * create) are owned by the parent page.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('lucide-vue-next', () => ({
  ChevronRight: { template: '<span data-testid="chevron" />' },
}))

import MailTemplateListView from '@/components/admin/MailTemplateListView.vue'

const makeTemplate = (overrides = {}) => ({
  id: 1,
  name: 'welcome',
  subject: 'Hi',
  body_text: 'x',
  body_html: '<p>x</p>',
  ...overrides,
})

beforeEach(() => {
  vi.resetAllMocks()
})

describe('MailTemplateListView', () => {
  it('renders the loading state', () => {
    const wrapper = mount(MailTemplateListView, {
      props: { templates: [], loading: true },
    })
    expect(wrapper.text()).toContain('Loading…')
  })

  it('renders the empty state when there are no templates', () => {
    const wrapper = mount(MailTemplateListView, {
      props: { templates: [], loading: false },
    })
    expect(wrapper.text()).toContain('No templates yet.')
  })

  it('renders a button per template', () => {
    const wrapper = mount(MailTemplateListView, {
      props: {
        templates: [
          makeTemplate({ id: 1, name: 'welcome' }),
          makeTemplate({ id: 2, name: 'order_confirmation' }),
        ],
        loading: false,
      },
    })
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(2)
    expect(wrapper.text()).toContain('welcome')
    expect(wrapper.text()).toContain('order_confirmation')
  })

  it('shows a "System" badge for system template names', () => {
    const wrapper = mount(MailTemplateListView, {
      props: {
        templates: [makeTemplate({ id: 1, name: 'email_verification' })],
        loading: false,
      },
    })
    expect(wrapper.text()).toContain('System')
  })

  it('does not show a "System" badge for custom template names', () => {
    const wrapper = mount(MailTemplateListView, {
      props: {
        templates: [makeTemplate({ id: 1, name: 'custom_one' })],
        loading: false,
      },
    })
    expect(wrapper.text()).not.toContain('System')
  })

  it('emits `select` with the clicked template', async () => {
    const t = makeTemplate({ id: 42, name: 'welcome' })
    const wrapper = mount(MailTemplateListView, {
      props: { templates: [t], loading: false },
    })
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('select')).toBeTruthy()
    expect(wrapper.emitted('select')![0]).toEqual([t])
  })
})
