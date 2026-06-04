// Vitest global setup - mocks for browser APIs not available in happy-dom
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