import { describe, expect, it } from 'vitest'
import {
  resolveOperationEnabled,
  resolveRequiresApproval,
  resolveOperation,
} from '@/utils/toolOperations'
import type { ToolOperation, OperationOverride } from '@/utils/toolOperations'

const readInbox: ToolOperation = {
  name: 'read_inbox',
  description: 'Read emails from inbox',
  enabledByDefault: true,
  requiresApprovalByDefault: false,
  discriminatorKey: 'action',
}

const sendEmail: ToolOperation = {
  name: 'send_email',
  description: 'Send an email',
  enabledByDefault: false,
  requiresApprovalByDefault: true,
  discriminatorKey: 'action',
}

describe('resolveOperationEnabled', () => {
  it('returns class default when no override', () => {
    expect(resolveOperationEnabled(readInbox, null)).toBe(true)
    expect(resolveOperationEnabled(sendEmail, null)).toBe(false)
  })

  it('returns override when set to true', () => {
    const override: OperationOverride = { enabled: true, default_requires_approval: null }
    expect(resolveOperationEnabled(sendEmail, override)).toBe(true)
  })

  it('returns override when set to false', () => {
    const override: OperationOverride = { enabled: false, default_requires_approval: null }
    expect(resolveOperationEnabled(readInbox, override)).toBe(false)
  })

  it('null override.enabled means use default', () => {
    const override: OperationOverride = { enabled: null, default_requires_approval: null }
    expect(resolveOperationEnabled(readInbox, override)).toBe(true)
    expect(resolveOperationEnabled(sendEmail, override)).toBe(false)
  })
})

describe('resolveRequiresApproval', () => {
  it('returns class default when no override', () => {
    // read_inbox: requiresApprovalByDefault=false → should NOT require approval
    expect(resolveRequiresApproval(readInbox, null)).toBe(false)
    // send_email: requiresApprovalByDefault=true → SHOULD require approval
    expect(resolveRequiresApproval(sendEmail, null)).toBe(true)
  })

  it('returns override when set to false (auto-approve enabled)', () => {
    // User enabled auto-approve → stored as default_requires_approval: false
    const override: OperationOverride = { enabled: null, default_requires_approval: false }
    expect(resolveRequiresApproval(sendEmail, override)).toBe(false)
  })

  it('returns override when set to true (manual approval required)', () => {
    // User disabled auto-approve → stored as default_requires_approval: true
    const override: OperationOverride = { enabled: null, default_requires_approval: true }
    expect(resolveRequiresApproval(readInbox, override)).toBe(true)
  })

  it('null override.default_requires_approval means use default', () => {
    const override: OperationOverride = { enabled: null, default_requires_approval: null }
    expect(resolveRequiresApproval(readInbox, override)).toBe(false)
    expect(resolveRequiresApproval(sendEmail, override)).toBe(true)
  })
})

describe('resolveOperation', () => {
  it('resolves both flags from defaults', () => {
    expect(resolveOperation(readInbox, null)).toEqual({ enabled: true, requiresApproval: false })
    expect(resolveOperation(sendEmail, null)).toEqual({ enabled: false, requiresApproval: true })
  })

  it('resolves with both override flags set', () => {
    // User enables send_email and turns on auto-approve
    const override: OperationOverride = { enabled: true, default_requires_approval: false }
    expect(resolveOperation(sendEmail, override)).toEqual({ enabled: true, requiresApproval: false })
  })

  it('resolves with partial override (only enabled)', () => {
    const override: OperationOverride = { enabled: true, default_requires_approval: null }
    expect(resolveOperation(sendEmail, override)).toEqual({ enabled: true, requiresApproval: true })
  })

  it('resolves with partial override (only requires_approval)', () => {
    const override: OperationOverride = { enabled: null, default_requires_approval: false }
    expect(resolveOperation(readInbox, override)).toEqual({ enabled: true, requiresApproval: false })
  })

  it('disabled operation always returns enabled=false regardless of requires_approval override', () => {
    const override: OperationOverride = { enabled: false, default_requires_approval: false }
    expect(resolveOperation(readInbox, override)).toEqual({ enabled: false, requiresApproval: false })
    expect(resolveOperation(sendEmail, override)).toEqual({ enabled: false, requiresApproval: false })
  })
})
