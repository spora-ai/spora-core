// Vitest global setup - mocks for browser APIs not available in happy-dom
global.EventSource = class EventSource {
  static CONNECTING = 0
  static OPEN = 1
  static CLOSED = 3

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