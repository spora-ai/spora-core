/**
 * useMarkdown — tests for the markdown renderer.
 *
 * Verifies:
 *  - Plain markdown becomes HTML
 *  - Code blocks include language label and code-block wrapper
 *  - Sanitizer strips dangerous protocols and scripts
 */
import { describe, it, expect } from 'vitest'
import { renderMarkdown } from '@/composables/useMarkdown'

describe('renderMarkdown', () => {
  it('renders basic paragraphs', () => {
    const html = renderMarkdown('Hello **world**')
    expect(html).toContain('<p>')
    expect(html).toContain('<strong>world</strong>')
  })

  it('renders headings', () => {
    const html = renderMarkdown('# Title\n\n## Sub')
    expect(html).toContain('<h1>Title</h1>')
    expect(html).toContain('<h2>Sub</h2>')
  })

  it('renders fenced code blocks with the code-block wrapper', () => {
    const html = renderMarkdown('```js\nconsole.log("hi")\n```')
    expect(html).toContain('class="code-block"')
    expect(html).toContain('data-code=')
    expect(html).toContain('code-block-lang')
  })

  it('still produces a code-block wrapper when no language is set', () => {
    const html = renderMarkdown('```\nplain\n```')
    expect(html).toContain('code-block-lang')
    expect(html).toContain('plain')
    expect(html).toContain('class="code-block"')
  })

  it('includes the copy button placeholder rewritten to copy button', () => {
    const html = renderMarkdown('```ts\nconst x = 1\n```')
    expect(html).toContain('code-block-copy')
    expect(html).toContain('Copy')
  })

  it('falls back to default code styling when language is unknown', () => {
    const html = renderMarkdown('```someweirdlang\nhello\n```')
    expect(html).toContain('class="code-block"')
    // Unknown language — no language-* class
    expect(html).toContain('hello')
  })

  it('returns sanitized HTML — strips <script> tags', () => {
    const html = renderMarkdown('Hi <script>alert(1)</script>')
    expect(html).not.toContain('<script>')
  })

  it('strips javascript: URLs from anchor tags', () => {
    const html = renderMarkdown('[click](javascript:alert(1))')
    expect(html).not.toMatch(/href="javascript:/i)
  })

  it('preserves http and https links', () => {
    const html = renderMarkdown('[click](https://example.com)')
    expect(html).toContain('href="https://example.com"')
  })

  it('handles empty string input', () => {
    expect(renderMarkdown('')).toBe('')
  })

  it('accepts a missing argument via the default parameter (SonarQube S7760)', () => {
    // Replaces the previous `const raw = src ?? ''` reassignment with a true
    // default parameter. The behaviour must be identical for the empty case.
    expect(renderMarkdown()).toBe('')
  })

  it('renders unordered and ordered lists', () => {
    const html = renderMarkdown('- a\n- b\n\n1. one\n2. two')
    expect(html).toContain('<ul>')
    expect(html).toContain('<ol>')
  })

  it('renders inline code', () => {
    const html = renderMarkdown('Use `inline` code.')
    expect(html).toContain('<code>inline</code>')
  })
})
