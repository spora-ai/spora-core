/**
 * PluginCard — single plugin summary tile.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, vi } from 'vitest'
import PluginCard from '@/apps/plugins/components/PluginCard.vue'
import MigrationStatusBadge from '@/apps/plugins/components/MigrationStatusBadge.vue'
import type { PluginResource } from '@/apps/plugins/types/plugin'

const basePlugin: PluginResource = {
  slug: 'minimax',
  name: 'MiniMax',
  description: 'Image, speech, music, lyrics, and video generation.',
  icon: 'puzzle',
  version: 1,
  path: '/plugins/minimax',
  bundledTools: [{ name: 'image', description: 'Generate an image' }],
  bundledDrivers: [{ provider: 'minimax', class: 'Spora\\Plugins\\MiniMax\\Driver' }],
  recipePaths: [],
  migrations: {
    declared: 1,
    applied: 1,
    filesOnDisk: 1,
    pending: 0,
    lastAppliedAt: '2026-06-09 10:15:32',
    status: 'up_to_date',
  },
}

describe('PluginCard', () => {
  it('renders the plugin name and description', () => {
    const wrapper = mount(PluginCard, { props: { plugin: basePlugin } })
    expect(wrapper.text()).toContain('MiniMax')
    expect(wrapper.text()).toContain('Image, speech, music')
  })

  it('shows the schema version when > 0', () => {
    const wrapper = mount(CardWithBadge, { props: { plugin: basePlugin } })
    expect(wrapper.text()).toContain('v1')
  })

  it('hides the version label when version is 0', () => {
    const wrapper = mount(CardWithBadge, { props: { plugin: { ...basePlugin, version: 0 } } })
    expect(wrapper.text()).not.toContain('v0')
  })

  it('shows bundled tool / driver / recipe counts', () => {
    const wrapper = mount(CardWithBadge, { props: { plugin: basePlugin } })
    const counts = wrapper.findAll('span[title]')
    expect(counts.length).toBeGreaterThanOrEqual(3)
  })

  it('falls back to the slug when no description is set', () => {
    const wrapper = mount(CardWithBadge, { props: { plugin: { ...basePlugin, description: '' } } })
    expect(wrapper.text()).toContain('Plugin slug: minimax')
  })

  it('emits select with the plugin when clicked', async () => {
    const wrapper = mount(PluginCard, { props: { plugin: basePlugin } })
    await wrapper.trigger('click')
    expect(wrapper.emitted('select')).toBeTruthy()
    expect(wrapper.emitted('select')![0]).toEqual([basePlugin])
  })
})

// Reusable mount with the badge stubbed — keeps DOM assertions small and stable.
import { h } from 'vue'
const CardWithBadge = {
  components: { PluginCard, MigrationStatusBadge },
  props: { plugin: { type: Object, required: true } },
  setup(props) {
    return () => h(PluginCard, { plugin: props.plugin })
  },
}
