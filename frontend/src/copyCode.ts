/**
 * Delegated copy-code handler for code blocks rendered via v-html.
 */
document.addEventListener('click', async (e: MouseEvent) => {
  const btn = (e.target as HTMLElement).closest('.code-block-copy') as HTMLElement | null
  if (!btn) return

  const code = decodeURIComponent((btn.closest('.code-block') as HTMLElement)?.dataset.code ?? '')
  if (!code) return

  const icon = btn.querySelector('.code-block-copy-icon')
  const iconHtml = icon?.outerHTML ?? ''

  try {
    await navigator.clipboard.writeText(code)
    btn.classList.add('copied')
    btn.textContent = ''
    if (icon) btn.appendChild(icon.cloneNode(true))
    btn.appendChild(document.createTextNode(' Copied!'))
    setTimeout(() => {
      btn.classList.remove('copied')
      btn.textContent = ''
      if (iconHtml) {
        const tmp = document.createElement('div')
        tmp.innerHTML = iconHtml
        btn.appendChild(tmp.firstChild!.cloneNode(true))
      }
      btn.appendChild(document.createTextNode(' Copy'))
    }, 2000)
  } catch {
    btn.textContent = ''
    if (iconHtml) {
      const tmp = document.createElement('div')
      tmp.innerHTML = iconHtml
      btn.appendChild(tmp.firstChild!.cloneNode(true))
    }
    btn.appendChild(document.createTextNode(' Failed'))
    setTimeout(() => {
      btn.classList.remove('copied')
      btn.textContent = ''
      if (iconHtml) {
        const tmp = document.createElement('div')
        tmp.innerHTML = iconHtml
        btn.appendChild(tmp.firstChild!.cloneNode(true))
      }
      btn.appendChild(document.createTextNode(' Copy'))
    }, 2000)
  }
})
