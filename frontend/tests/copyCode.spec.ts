/**
 * copyCode — global click handler that copies code blocks to the clipboard.
 *
 * The module attaches a single `click` listener to `document` on import.
 * We mock `navigator.clipboard.writeText` LOCALLY (the coordinator owns
 * adding clipboard to setup.ts), then dispatch synthetic clicks against
 * a code-block fixture and verify the listener behaved correctly.
 */
import { describe, it, expect, vi, beforeEach, afterEach, beforeAll } from 'vitest'

const writeTextMock = vi.fn().mockResolvedValue(undefined)

beforeAll(() => {
  Object.defineProperty(globalThis.navigator, 'clipboard', {
    configurable: true,
    value: { writeText: writeTextMock },
  })
})

// Import once so the listener is attached
beforeAll(async () => {
  await import('@/copyCode')
})

function makeCodeBlock(code: string): HTMLElement {
  const block = document.createElement('div')
  block.className = 'code-block'
  block.dataset.code = encodeURIComponent(code)
  block.innerHTML = `
    <div class="code-block-header">
      <span class="code-block-lang">js</span>
      <span class="code-block-copy">
        <svg class="code-block-copy-icon"></svg>Copy
      </span>
    </div>
    <pre><code>${code}</code></pre>
  `
  document.body.appendChild(block)
  return block
}

describe('copyCode click handler', () => {
  beforeEach(() => {
    writeTextMock.mockReset().mockResolvedValue(undefined)
    document.body.innerHTML = ''
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('copies the decoded code content when the copy button is clicked', async () => {
    const block = makeCodeBlock('console.log("hi")')
    const btn = block.querySelector('.code-block-copy') as HTMLElement

    btn.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    // Wait for the click handler's async writeText() promise
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(writeTextMock).toHaveBeenCalledWith('console.log("hi")')
  })

  it('does nothing when click target is not inside a copy button', () => {
    const block = makeCodeBlock('hello')
    const codeEl = block.querySelector('code') as HTMLElement

    codeEl.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(writeTextMock).not.toHaveBeenCalled()
  })

  it('does nothing when the code-block has no data-code', () => {
    const block = document.createElement('div')
    block.className = 'code-block'
    block.innerHTML = `<span class="code-block-copy">Copy</span>`
    document.body.appendChild(block)
    const btn = block.querySelector('.code-block-copy') as HTMLElement

    btn.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(writeTextMock).not.toHaveBeenCalled()
  })

  it('falls back to "Failed" label when writeText rejects', async () => {
    writeTextMock.mockRejectedValueOnce(new Error('no clipboard'))
    const block = makeCodeBlock('boom')
    const btn = block.querySelector('.code-block-copy') as HTMLElement

    btn.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    // Wait for the promise chain to flush
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(btn.textContent).toContain('Failed')
  })
})
