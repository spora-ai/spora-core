/**
 * Icon — thin wrapper around the inline SVG path map.
 *
 * Resolution order:
 *   1. Bundled-name lookup (single or multi-path icon)
 *   2. Raw SVG path string (plugin-supplied icons)
 *   3. Fallback to bell
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import Icon from '@/components/ui/Icon.vue'

describe('Icon', () => {
  it('renders a single <path> for single-path bundled icons', () => {
    const wrapper = mount(Icon, { props: { name: 'check' } })
    expect(wrapper.findAll('path')).toHaveLength(1)
  })

  it('renders multiple <path> elements for multi-path bundled icons (brain)', () => {
    const wrapper = mount(Icon, { props: { name: 'brain' } })
    expect(wrapper.findAll('path').length).toBeGreaterThan(1)
  })

  it('renders one <path> for the single-path puzzle icon', () => {
    const wrapper = mount(Icon, { props: { name: 'puzzle' } })
    expect(wrapper.findAll('path')).toHaveLength(1)
  })

  it('renders a plugin-supplied raw SVG path', () => {
    const customPath = 'M12 2L2 22h20L12 2z'
    const wrapper = mount(Icon, { props: { name: customPath } })
    expect(wrapper.findAll('path')).toHaveLength(1)
    expect(wrapper.find('path').attributes('d')).toBe(customPath)
  })

  it('falls back to bell for unknown icon names', () => {
    const wrapper = mount(Icon, { props: { name: 'definitely-not-a-real-icon' } })
    const bellWrapper = mount(Icon, { props: { name: 'bell' } })
    expect(wrapper.find('path').attributes('d')).toBe(bellWrapper.find('path').attributes('d'))
  })

  it('falls back to bell when the name is empty', () => {
    const wrapper = mount(Icon, { props: { name: '' } })
    const bellWrapper = mount(Icon, { props: { name: 'bell' } })
    expect(wrapper.find('path').attributes('d')).toBe(bellWrapper.find('path').attributes('d'))
  })

  // Curated default palette — gives plugin / app authors a menu to pick from
  // without coordinating with the Spora frontend. All paths lifted from
  // lucide-vue-next v0.487.0. See the inline NOTE in Icon.vue for icons
  // skipped here because they need non-<path> SVG elements (play, image, video).
  it.each([
    ['lightbulb', 3],
    ['file-text', 5],
    ['compass', 1],
    ['globe', 2],
    ['sparkles', 5],
    ['music', 1],
    ['database', 2],
    ['search', 1],
    ['mail', 1],
    ['calendar', 3],
    ['zap', 1],
    ['code', 1],
  ] as const)('renders bundled icon "%s" with %i path(s)', (name, expectedPaths) => {
    const wrapper = mount(Icon, { props: { name } })
    expect(wrapper.findAll('path')).toHaveLength(expectedPaths)
  })
})
