import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ToolSettingField from '@/components/settings/ToolSettingField.vue'
import type { ToolSettingSchema } from '@/composables/useToolSettings'

const global = { stubs: { Icon: true } }

function makeField(overrides: Partial<ToolSettingSchema> = {}): ToolSettingSchema {
  return {
    key: 'api_key',
    label: 'API Key',
    type: 'text',
    description: 'Your API key',
    default: null,
    required: false,
    options: null,
    expose_to_llm: false,
    ...overrides,
  }
}

describe('ToolSettingField', () => {
  describe('label rendering', () => {
    it('renders the field label by default', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '', field: makeField() },
        global,
      })

      expect(wrapper.text()).toContain('API Key')
    })

    it('hides the label when hideLabel is true', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '', field: makeField(), hideLabel: true },
        global,
      })

      expect(wrapper.text()).not.toContain('API Key')
    })

    it('marks the label as required when the field is required', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '', field: makeField({ required: true }) },
        global,
      })

      expect(wrapper.text()).toContain('*')
    })
  })

  describe('text input', () => {
    it('renders a text input by default', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: 'hello', field: makeField() },
        global,
      })

      const input = wrapper.find('input[type="text"]')
      expect(input.exists()).toBe(true)
      expect((input.element as HTMLInputElement).value).toBe('hello')
    })

    it('emits update:modelValue when the input changes', async () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '', field: makeField() },
        global,
      })

      const input = wrapper.find('input[type="text"]')
      await input.setValue('new value')

      const events = wrapper.emitted('update:modelValue')
      expect(events).toBeTruthy()
      expect(events![0][0]).toBe('new value')
    })
  })

  describe('textarea', () => {
    it('renders a textarea for type=textarea', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: 'multi\nline', field: makeField({ type: 'textarea' }) },
        global,
      })

      const textarea = wrapper.find('textarea')
      expect(textarea.exists()).toBe(true)
    })
  })

  describe('select', () => {
    it('renders a select for type=select with object options', () => {
      const wrapper = mount(ToolSettingField, {
        props: {
          modelValue: 'metric',
          field: makeField({ type: 'select', options: { metric: 'Metric', imperial: 'Imperial' } }),
        },
        global,
      })

      const select = wrapper.find('select')
      expect(select.exists()).toBe(true)
      const options = wrapper.findAll('option')
      expect(options.length).toBeGreaterThan(0)
    })
  })

  describe('toggle', () => {
    it('renders a Toggle for type=toggle', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: true, field: makeField({ type: 'toggle' }) },
        global,
      })

      const toggles = wrapper.findAllComponents({ name: 'Toggle' })
      expect(toggles.length).toBe(1)
    })
  })

  describe('password', () => {
    it('shows the locked display when value is the masked sentinel', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '***', field: makeField({ type: 'password' }) },
        global,
      })

      // The locked display uses bullet characters
      expect(wrapper.text()).toContain('•')
      expect(wrapper.text()).toContain('Change')
    })

    it('emits empty string when Change is clicked', async () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '***', field: makeField({ type: 'password' }) },
        global,
      })

      const changeBtn = wrapper.findAll('button').find(b => b.text() === 'Change')!
      await changeBtn.trigger('click')

      const events = wrapper.emitted('update:modelValue')
      expect(events).toBeTruthy()
      expect(events![0][0]).toBe('')
    })

    it('shows a password input when the user clicks Change', async () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '***', field: makeField({ type: 'password' }) },
        global,
      })

      const changeBtn = wrapper.findAll('button').find(b => b.text() === 'Change')!
      await changeBtn.trigger('click')

      const passwordInput = wrapper.find('input[type="password"]')
      expect(passwordInput.exists()).toBe(true)
    })

    it('exits edit mode and emits masked sentinel when Cancel is clicked', async () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '***', field: makeField({ type: 'password' }) },
        global,
      })

      // Enter edit mode by clicking Change
      const changeBtn = wrapper.findAll('button').find(b => b.text() === 'Change')!
      await changeBtn.trigger('click')

      // Type a new password
      const passwordInput = wrapper.find('input[type="password"]')
      await passwordInput.setValue('my new password')

      // Now click Cancel
      const cancelBtn = wrapper.findAll('button').find(b => b.text() === 'Cancel')!
      await cancelBtn.trigger('click')

      const events = wrapper.emitted('update:modelValue')
      expect(events).toBeTruthy()
      // Last event should be the '***' sentinel
      expect(events![events!.length - 1][0]).toBe('***')
    })
  })

  describe('error and disabled states', () => {
    it('shows the error message when error prop is set', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '', field: makeField(), error: 'Required field' },
        global,
      })

      expect(wrapper.text()).toContain('Required field')
    })

    it('disables the input when disabled prop is true', () => {
      const wrapper = mount(ToolSettingField, {
        props: { modelValue: '', field: makeField(), disabled: true },
        global,
      })

      const input = wrapper.find('input[type="text"]')
      expect(input.attributes('disabled')).toBeDefined()
    })
  })
})
