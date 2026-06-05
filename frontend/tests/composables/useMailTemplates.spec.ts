/**
 * useMailTemplates — pure helpers for the admin MailTemplatesPage.
 */
import { describe, it, expect } from 'vitest'
import {
  MAIL_TEMPLATE_SYSTEM_NAMES,
  MAIL_TEMPLATE_PLACEHOLDERS,
  isSystemTemplate,
  formatPlaceholder,
  insertPlaceholderInto,
  buildUpdatePayload,
  buildCreatePayload,
  validateCreateTemplate,
  emptyCreateDraft,
} from '@/composables/useMailTemplates'

describe('useMailTemplates helpers', () => {
  describe('constants', () => {
    it('exposes system template names', () => {
      expect(MAIL_TEMPLATE_SYSTEM_NAMES).toContain('email_verification')
      expect(MAIL_TEMPLATE_SYSTEM_NAMES).toContain('password_reset')
      expect(MAIL_TEMPLATE_SYSTEM_NAMES).toContain('welcome')
    })

    it('exposes placeholder registry', () => {
      expect(MAIL_TEMPLATE_PLACEHOLDERS).toContain('user_name')
      expect(MAIL_TEMPLATE_PLACEHOLDERS).toContain('verification_link')
    })
  })

  describe('isSystemTemplate', () => {
    it('returns true for protected names', () => {
      expect(isSystemTemplate('email_verification')).toBe(true)
      expect(isSystemTemplate('password_reset')).toBe(true)
      expect(isSystemTemplate('welcome')).toBe(true)
    })

    it('returns false for custom names', () => {
      expect(isSystemTemplate('custom_template')).toBe(false)
    })

    it('returns false for null/undefined/empty', () => {
      expect(isSystemTemplate(null)).toBe(false)
      expect(isSystemTemplate(undefined)).toBe(false)
      expect(isSystemTemplate('')).toBe(false)
    })
  })

  describe('formatPlaceholder', () => {
    it('wraps a placeholder in {{...}}', () => {
      expect(formatPlaceholder('user_name')).toBe('{{user_name}}')
    })
  })

  describe('insertPlaceholderInto', () => {
    it('appends to both text and html bodies', () => {
      const out = insertPlaceholderInto('Hello ', '<p>Hello </p>', 'user_name')
      expect(out.body_text).toBe('Hello {{user_name}}')
      expect(out.body_html).toBe('<p>Hello </p>{{user_name}}')
    })

    it('handles empty bodies', () => {
      const out = insertPlaceholderInto('', '', 'email')
      expect(out.body_text).toBe('{{email}}')
      expect(out.body_html).toBe('{{email}}')
    })
  })

  describe('buildUpdatePayload', () => {
    it('returns null for empty bodies', () => {
      expect(buildUpdatePayload({ subject: 'Hi', body_text: '', body_html: '' })).toEqual({
        subject: 'Hi',
        body_text: null,
        body_html: null,
      })
    })

    it('keeps populated bodies', () => {
      expect(
        buildUpdatePayload({ subject: 'Hi', body_text: 'Plain', body_html: '<p>Plain</p>' }),
      ).toEqual({
        subject: 'Hi',
        body_text: 'Plain',
        body_html: '<p>Plain</p>',
      })
    })
  })

  describe('buildCreatePayload', () => {
    it('trims name and subject and null-coerces empty bodies', () => {
      expect(
        buildCreatePayload({
          name: '  welcome2  ',
          subject: '  Hi  ',
          body_text: '',
          body_html: '',
        }),
      ).toEqual({
        name: 'welcome2',
        subject: 'Hi',
        body_text: null,
        body_html: null,
      })
    })
  })

  describe('validateCreateTemplate', () => {
    it('errors when name is empty', () => {
      expect(validateCreateTemplate({ name: '   ', subject: 'Hi', body_text: '', body_html: '' })).toBe(
        'Name is required.',
      )
    })

    it('errors when subject is empty', () => {
      expect(validateCreateTemplate({ name: 'welcome', subject: '   ', body_text: '', body_html: '' })).toBe(
        'Subject is required.',
      )
    })

    it('returns null when both are filled', () => {
      expect(validateCreateTemplate({ name: 'welcome', subject: 'Hi', body_text: '', body_html: '' })).toBeNull()
    })
  })

  describe('emptyCreateDraft', () => {
    it('returns an empty draft', () => {
      expect(emptyCreateDraft()).toEqual({
        name: '',
        subject: '',
        body_text: '',
        body_html: '',
      })
    })
  })
})
