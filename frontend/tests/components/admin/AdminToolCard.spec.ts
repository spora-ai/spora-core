/**
 * AdminToolCard — single tool config card.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'

import AdminToolCard from '@/components/admin/AdminToolCard.vue'

const tool = {
  tool_class: 'Spora\\Tools\\WebSearch',
  tool_name: 'web_search',
  display_name: 'Web Search',
  description: 'Search the web',
  category: 'web',
  settings_schema: [],
}

describe('AdminToolCard', () => {
  it('renders the tool display name', () => {
    const wrapper = mount(AdminToolCard, {
      props: { tool, settings: {}, saving: false, error: null },
    })
    expect(wrapper.text()).toContain('Web Search')
  })
})
