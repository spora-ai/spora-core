import { marked, Renderer } from 'marked'
import hljs from 'highlight.js'
import DOMPurify from 'dompurify'

const renderer = new Renderer()
renderer.code = ({ text, lang }: { text: string; lang?: string }) => {
  const language = lang && hljs.getLanguage(lang) ? lang : null
  const highlighted = language
    ? hljs.highlight(text, { language }).value
    : text
  const langClass = language ? ` language-${language}` : ''
  return `<pre><code class="hljs${langClass}">${highlighted}</code></pre>`
}
marked.use({ renderer })

export function renderMarkdown(src: string): string {
  const raw = src ?? ''
  let html: string
  try {
    html = marked.parse(raw) as string
  } catch {
    // Fall back to plain text if marked throws (e.g. pathological input)
    return raw
  }
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: [
      'p', 'br', 'strong', 'em', 'code', 'pre',
      'ul', 'ol', 'li', 'h1', 'h2', 'h3',
      'blockquote', 'a', 'table', 'thead', 'tbody',
      'tr', 'th', 'td', 'span', 'div',
    ],
    ALLOWED_ATTR: ['href', 'class'],
    ALLOWED_URI_REGEXP: /^(?!javascript:|data:)/,
  })
}
