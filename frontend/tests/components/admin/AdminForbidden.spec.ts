/**
 * AdminForbidden — shown when the current user is not an admin.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'

import AdminForbidden from '@/components/admin/AdminForbidden.vue'

describe('AdminForbidden', () => {
  it('renders a forbidden message', () => {
    const wrapper = mount(AdminForbidden)
    expect(wrapper.text()).toMatch(/forbidden|admin|privileges|access/i)
  })
})
