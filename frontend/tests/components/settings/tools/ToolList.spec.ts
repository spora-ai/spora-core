/**
 * ToolList — list of configurable tools (those with at least one setting).
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ToolList from '@/components/settings/tools/ToolList.vue'

const makeTool = (name: string, settingsCount: number) => ({
  tool_class: name,
  tool_name: name,
  display_name: name.toUpperCase(),
  category: '',
  settings_schema: Array(settingsCount).fill({ key: 'k', label: 'L', type: 'text' as const, required: false, default: '', description: '', options: null, expose_to_llm: false }),
  operations: [],
})

describe('ToolList', () => {
  it('renders only tools with settings', () => {
    const wrapper = mount(ToolList, { props: { tools: [makeTool('a', 1), makeTool('b', 0), makeTool('c', 2)] } })
    expect(wrapper.text()).toContain('A')
    expect(wrapper.text()).toContain('C')
    expect(wrapper.text()).not.toContain('B')
  })

  it('shows the number of settings per tool with proper pluralization', () => {
    const wrapper = mount(ToolList, { props: { tools: [makeTool('a', 1), makeTool('b', 2)] } })
    expect(wrapper.text()).toContain('1 setting')
    expect(wrapper.text()).toContain('2 settings')
  })

  it('emits select with the tool name when a row is clicked', async () => {
    const wrapper = mount(ToolList, { props: { tools: [makeTool('pick', 1)] } })
    const btn = wrapper.findAll('button').find((b) => b.text().includes('PICK'))
    expect(btn).toBeDefined()
    await btn!.trigger('click')
    expect(wrapper.emitted('select')).toBeTruthy()
    expect(wrapper.emitted('select')![0]).toEqual(['pick'])
  })

  it('highlights the currently selected tool', () => {
    const wrapper = mount(ToolList, { props: { tools: [makeTool('a', 1), makeTool('b', 1)], selectedToolId: 'b' } })
    const selectedBtn = wrapper.findAll('button').find((b) => b.text().includes('B'))
    expect(selectedBtn?.classes()).toContain('bg-muted/70')
  })

  it('renders nothing in the list when no tools have settings', () => {
    const wrapper = mount(ToolList, { props: { tools: [makeTool('a', 0)] } })
    expect(wrapper.findAll('button').length).toBe(0)
  })

  it('falls back to tool_name when display_name is null', () => {
    const tools = [{ tool_class: 'X', tool_name: 'xname', display_name: null, category: '', settings_schema: [{ key: 'k', label: 'L', type: 'text' as const, required: false, default: '', description: '', options: null, expose_to_llm: false }], operations: [] }]
    const wrapper = mount(ToolList, { props: { tools } })
    expect(wrapper.text()).toContain('xname')
  })
})
