// Vitest global setup - mocks for browser APIs not available in happy-dom
import { vi } from 'vitest'

// Stub navigator.clipboard so `copyCode.ts` (and any other module that
// writes to the clipboard) can be exercised in tests without a real
// clipboard. The test for the copy-to-code action asserts on this mock.
if (typeof navigator === 'undefined' || !navigator.clipboard) {
  Object.defineProperty(globalThis, 'navigator', {
    value: { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } },
    configurable: true,
    writable: true,
  })
} else {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
    configurable: true,
    writable: true,
  })
}

globalThis.EventSource = class EventSource {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSED = 3

  url: string
  readyState = EventSource.CONNECTING

  constructor(url: string) {
    this.url = url
    // Simulate async connection
    setTimeout(() => {
      this.readyState = EventSource.OPEN
    }, 0)
  }

  close() {
    this.readyState = EventSource.CLOSED
  }
}