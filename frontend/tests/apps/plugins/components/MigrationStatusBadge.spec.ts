/**
 * MigrationStatusBadge — color-coded pill for plugin migration state.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import MigrationStatusBadge from '@/apps/plugins/components/MigrationStatusBadge.vue'

describe('MigrationStatusBadge', () => {
  it('renders "No migrations" for no_migrations', () => {
    const wrapper = mount(MigrationStatusBadge, { props: { status: 'no_migrations' } })
    expect(wrapper.text()).toContain('No migrations')
  })

  it('renders "Up to date" for up_to_date', () => {
    const wrapper = mount(MigrationStatusBadge, { props: { status: 'up_to_date' } })
    expect(wrapper.text()).toContain('Up to date')
  })

  it('renders the pending count for pending_migrations', () => {
    const wrapper = mount(MigrationStatusBadge, { props: { status: 'pending_migrations', pending: 3 } })
    expect(wrapper.text()).toContain('3 pending')
  })

  it('renders 0 pending when status is pending_migrations but no count is given', () => {
    const wrapper = mount(MigrationStatusBadge, { props: { status: 'pending_migrations' } })
    expect(wrapper.text()).toContain('0 pending')
  })
})
