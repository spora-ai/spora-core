/**
 * Icon — thin wrapper around the inline SVG element map.
 *
 * Each bundled icon is a list of SVG primitives (path, circle, ellipse,
 * polyline, polygon, rect). The template renders one element per entry,
 * dispatching on `tag`. The resolution order is:
 *   1. Bundled-name lookup
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

  it('renders 9 <path> elements for the multi-path brain icon', () => {
    const wrapper = mount(Icon, { props: { name: 'brain' } })
    expect(wrapper.findAll('path')).toHaveLength(9)
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

  // Non-path SVG primitives — the icons that motivated the generic
  // renderer. Each had a missing element (circle, ellipse, polyline)
  // before the renderer was extended.
  describe('non-path elements', () => {
    it('renders the search icon as a <circle> + <path>', () => {
      const wrapper = mount(Icon, { props: { name: 'search' } })
      expect(wrapper.findAll('circle')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(1)
      // The circle's center is at (11, 11) and its radius is 8.
      const circle = wrapper.find('circle')
      expect(circle.attributes('cx')).toBe('11')
      expect(circle.attributes('cy')).toBe('11')
      expect(circle.attributes('r')).toBe('8')
    })

    it('renders the database icon as an <ellipse> + 2 <path>s', () => {
      const wrapper = mount(Icon, { props: { name: 'database' } })
      expect(wrapper.findAll('ellipse')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(2)
      // The top ellipse is at (12, 5) with rx=9, ry=3.
      const ellipse = wrapper.find('ellipse')
      expect(ellipse.attributes('cx')).toBe('12')
      expect(ellipse.attributes('cy')).toBe('5')
      expect(ellipse.attributes('rx')).toBe('9')
      expect(ellipse.attributes('ry')).toBe('3')
    })

    it('renders the music icon as a <path> + 2 <circle>s (the notes)', () => {
      const wrapper = mount(Icon, { props: { name: 'music' } })
      expect(wrapper.findAll('path')).toHaveLength(1)
      expect(wrapper.findAll('circle')).toHaveLength(2)
      // Notes are at (6, 18) and (18, 16), both r=3.
      const circles = wrapper.findAll('circle')
      expect(circles[0].attributes('cx')).toBe('6')
      expect(circles[0].attributes('cy')).toBe('18')
      expect(circles[1].attributes('cx')).toBe('18')
      expect(circles[1].attributes('cy')).toBe('16')
    })

    it('renders the code icon as 2 <polyline>s (the < and > brackets)', () => {
      const wrapper = mount(Icon, { props: { name: 'code' } })
      expect(wrapper.findAll('polyline')).toHaveLength(2)
      expect(wrapper.findAll('path')).toHaveLength(0)
      // The two polylines form the brackets.
      const polylines = wrapper.findAll('polyline')
      expect(polylines[0].attributes('points')).toBe('16 18 22 12 16 6')
      expect(polylines[1].attributes('points')).toBe('8 6 2 12 8 18')
    })

    it('renders the play icon as a single <polygon>', () => {
      const wrapper = mount(Icon, { props: { name: 'play' } })
      expect(wrapper.findAll('polygon')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(0)
    })

    it('renders the image icon as <rect> + <circle> + <path>', () => {
      const wrapper = mount(Icon, { props: { name: 'image' } })
      expect(wrapper.findAll('rect')).toHaveLength(1)
      expect(wrapper.findAll('circle')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(1)
    })

    it('renders the video icon as <path> + <rect>', () => {
      const wrapper = mount(Icon, { props: { name: 'video' } })
      expect(wrapper.findAll('path')).toHaveLength(1)
      expect(wrapper.findAll('rect')).toHaveLength(1)
    })

    it('renders the compass icon as <circle> + <path> (the outer ring + the needle)', () => {
      const wrapper = mount(Icon, { props: { name: 'compass' } })
      expect(wrapper.findAll('circle')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(1)
      // The outer ring is centered at (12, 12) with radius 10.
      const circle = wrapper.find('circle')
      expect(circle.attributes('cx')).toBe('12')
      expect(circle.attributes('cy')).toBe('12')
      expect(circle.attributes('r')).toBe('10')
    })

    it('renders the globe icon as <circle> + 2 <path>s (sphere + meridian + equator)', () => {
      const wrapper = mount(Icon, { props: { name: 'globe' } })
      expect(wrapper.findAll('circle')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(2)
      const circle = wrapper.find('circle')
      expect(circle.attributes('cx')).toBe('12')
      expect(circle.attributes('cy')).toBe('12')
      expect(circle.attributes('r')).toBe('10')
    })

    it('renders the mail icon as <rect> + <path> (envelope body + the V fold)', () => {
      const wrapper = mount(Icon, { props: { name: 'mail' } })
      expect(wrapper.findAll('rect')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(1)
      const rect = wrapper.find('rect')
      expect(rect.attributes('width')).toBe('20')
      expect(rect.attributes('height')).toBe('16')
      expect(rect.attributes('x')).toBe('2')
      expect(rect.attributes('y')).toBe('4')
      expect(rect.attributes('rx')).toBe('2')
    })

    it('renders the calendar icon as 2 <path>s + <rect> + <path> (tabs + body + header divider)', () => {
      const wrapper = mount(Icon, { props: { name: 'calendar' } })
      expect(wrapper.findAll('rect')).toHaveLength(1)
      expect(wrapper.findAll('path')).toHaveLength(3)
      const rect = wrapper.find('rect')
      expect(rect.attributes('width')).toBe('18')
      expect(rect.attributes('height')).toBe('18')
      expect(rect.attributes('x')).toBe('3')
      expect(rect.attributes('y')).toBe('4')
      expect(rect.attributes('rx')).toBe('2')
    })
  })

  // Curated default palette — gives plugin / app authors a menu to pick from
  // without coordinating with the Spora frontend. All paths lifted from
  // lucide-vue-next v0.487.0. Now includes play/image/video (polygon/rect).
  describe('bundled palette', () => {
    it.each([
      ['lightbulb', 3],
      ['file-text', 5],
      ['compass', 1],
      ['globe', 2],
      ['sparkles', 5],
      ['mail', 1],
      ['calendar', 3],
      ['zap', 1],
    ] as const)('renders bundled icon "%s" with %i <path>(s)', (name, expectedPaths) => {
      const wrapper = mount(Icon, { props: { name } })
      expect(wrapper.findAll('path')).toHaveLength(expectedPaths)
    })
  })
})
