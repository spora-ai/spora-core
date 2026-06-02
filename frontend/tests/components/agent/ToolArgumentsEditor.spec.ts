import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ToolArgumentsEditor from '@/components/agent/ToolArgumentsEditor.vue'
import type { ParameterSchema } from '@/types/task'

// `Icon` is auto-registered globally in the app; stub it for unit tests.
const global = {
  stubs: { Icon: true },
}

function makeSchema(orderedKeys: string[]): ParameterSchema {
  const properties: ParameterSchema['properties'] = {}
  for (const k of orderedKeys) {
    properties[k] = { type: 'string' }
  }
  return { type: 'object', properties, required: [orderedKeys[0]] }
}

describe('ToolArgumentsEditor', () => {
  it('renders fields in parameterSchema declaration order regardless of arguments key order', () => {
    // LLM emitted the keys in wrong order; schema declares action, summary, start.
    const wrapper = mount(ToolArgumentsEditor, {
      props: {
        arguments: { start: '2026-06-01', action: 'create', summary: 'Team sync' },
        parameterSchema: makeSchema(['action', 'summary', 'start']),
      },
      global,
    })

    const labels = wrapper.findAll('label').map(l => l.text())
    expect(labels).toEqual(['Action', 'Summary', 'Start'])
  })

  it('falls back to legacy important-first sort when parameterSchema is not provided', () => {
    const wrapper = mount(ToolArgumentsEditor, {
      props: {
        arguments: { zzz: 'last', body: 'msg', aaa: 'first' },
      },
      global,
    })

    const labels = wrapper.findAll('label').map(l => l.text())
    // 'body' is "important" → first; 'aaa' before 'zzz' alphabetically.
    expect(labels[0]).toBe('Body')
    expect(labels.indexOf('Aaa')).toBeLessThan(labels.indexOf('Zzz'))
  })

  it('emits update:arguments as a JSON string when a field changes', async () => {
    const wrapper = mount(ToolArgumentsEditor, {
      props: {
        arguments: { q: 'hello' },
        parameterSchema: makeSchema(['q']),
      },
      global,
    })

    const input = wrapper.find('input[type="text"]')
    await input.setValue('world')

    const events = wrapper.emitted('update:arguments')
    expect(events).toBeTruthy()
    const lastEvent = events![events!.length - 1]
    const payload = JSON.parse(lastEvent[0] as string)
    expect(payload).toEqual({ q: 'world' })
  })

  it('renders the read-only JSON view for nested arguments', () => {
    const wrapper = mount(ToolArgumentsEditor, {
      props: {
        arguments: { filters: { country: 'us', language: 'en' } },
      },
      global,
    })

    // Nested → details summary "Arguments (complex structure)" appears
    expect(wrapper.text()).toContain('Arguments (complex structure)')
    // Flat-field labels do not appear (we never render labels for nested args)
    expect(wrapper.findAll('label')).toHaveLength(0)
  })

  it('accepts a JSON-string `arguments` prop and parses it', () => {
    const wrapper = mount(ToolArgumentsEditor, {
      props: {
        arguments: JSON.stringify({ action: 'send', body: 'msg' }),
        parameterSchema: makeSchema(['action', 'body']),
      },
      global,
    })

    const labels = wrapper.findAll('label').map(l => l.text())
    expect(labels).toEqual(['Action', 'Body'])
  })
})
