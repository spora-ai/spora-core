/**
 * AdminSection — reusable admin section wrapper.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'

import AdminSection from '@/components/admin/AdminSection.vue'

describe('AdminSection', () => {
  it('renders the title and description', () => {
    const wrapper = mount(AdminSection, {
      props: { title: 'My Section', description: 'Some description' },
      slots: { default: '<p>Body content</p>' },
    })
    expect(wrapper.text()).toContain('My Section')
    expect(wrapper.text()).toContain('Some description')
    expect(wrapper.text()).toContain('Body content')
  })

  it('renders slot content without title/description when not provided', () => {
    const wrapper = mount(AdminSection, {
      slots: { default: '<p>Body</p>' },
    })
    expect(wrapper.text()).toContain('Body')
  })
})
