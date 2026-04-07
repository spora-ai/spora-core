import { describe, it, expect } from 'vitest'
import { mapHttpStatus, mapErrorCode } from '@/utils/errorMapper'

describe('mapHttpStatus', () => {
  it('maps 2xx to success', () => {
    expect(mapHttpStatus(200)).toEqual({ severity: 'success' })
    expect(mapHttpStatus(201)).toEqual({ severity: 'success' })
    expect(mapHttpStatus(299)).toEqual({ severity: 'success' })
  })

  it('maps 401 to error with login action', () => {
    expect(mapHttpStatus(401)).toEqual({ severity: 'error', action: 'login' })
  })

  it('maps 403 to error', () => {
    expect(mapHttpStatus(403)).toEqual({ severity: 'error' })
  })

  it('maps 404 to warning', () => {
    expect(mapHttpStatus(404)).toEqual({ severity: 'warning' })
  })

  it('maps 429 to warning with retry action', () => {
    expect(mapHttpStatus(429)).toEqual({ severity: 'warning', action: 'retry' })
  })

  it('maps 500+ to error', () => {
    expect(mapHttpStatus(500)).toEqual({ severity: 'error' })
    expect(mapHttpStatus(502)).toEqual({ severity: 'error' })
    expect(mapHttpStatus(599)).toEqual({ severity: 'error' })
  })

  it('maps 400-499 (other than 401/403/404/429) to warning', () => {
    expect(mapHttpStatus(400)).toEqual({ severity: 'warning' })
    expect(mapHttpStatus(422)).toEqual({ severity: 'warning' })
    expect(mapHttpStatus(499)).toEqual({ severity: 'warning' })
  })
})

describe('mapErrorCode', () => {
  it('maps UNAUTHENTICATED to error with login action', () => {
    expect(mapErrorCode('UNAUTHENTICATED')).toEqual({ severity: 'error', action: 'login' })
  })

  it('maps ACCOUNT_UNVERIFIED and ACCOUNT_SUSPENDED to error', () => {
    expect(mapErrorCode('ACCOUNT_UNVERIFIED')).toEqual({ severity: 'error' })
    expect(mapErrorCode('ACCOUNT_SUSPENDED')).toEqual({ severity: 'error' })
  })

  it('maps RATE_LIMIT and TOO_MANY_REQUESTS to warning with retry', () => {
    expect(mapErrorCode('RATE_LIMIT')).toEqual({ severity: 'warning', action: 'retry' })
    expect(mapErrorCode('TOO_MANY_REQUESTS')).toEqual({ severity: 'warning', action: 'retry' })
  })

  it('maps LLM_RATE_LIMIT and LLM_PROVIDER_ERROR to warning with retry', () => {
    expect(mapErrorCode('LLM_RATE_LIMIT')).toEqual({ severity: 'warning', action: 'retry' })
    expect(mapErrorCode('LLM_PROVIDER_ERROR')).toEqual({ severity: 'warning', action: 'retry' })
  })

  it('maps DECRYPTION_FAILED to error with contact action', () => {
    expect(mapErrorCode('DECRYPTION_FAILED')).toEqual({ severity: 'error', action: 'contact' })
  })

  it('maps NOT_FOUND and INVALID_STATE to warning', () => {
    expect(mapErrorCode('NOT_FOUND')).toEqual({ severity: 'warning' })
    expect(mapErrorCode('INVALID_STATE')).toEqual({ severity: 'warning' })
  })

  it('maps unknown codes to warning', () => {
    expect(mapErrorCode('SOME_UNKNOWN_CODE')).toEqual({ severity: 'warning' })
  })
})