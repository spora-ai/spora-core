import { test, expect } from '@playwright/test'

test('continuation: run task to completion, submit continuation, verify task continues on same page', async ({ page }) => {
  await page.goto('http://localhost:8080/agents/1')

  // Submit a task
  await page.fill('[data-testid="composer-textarea"]', 'What is 2+2?')
  await page.click('[data-testid="composer-submit"]')

  // Wait for completion
  await page.waitForFunction(() => {
    return document.querySelector('[data-testid="task-status"]')?.textContent === 'COMPLETED'
  }, { timeout: 60000 })

  // Capture the original task URL
  const originalUrl = page.url()
  const taskIdMatch = originalUrl.match(/\/tasks\/(\d+)/)
  expect(taskIdMatch).not.toBeNull()
  const originalTaskId = taskIdMatch![1]

  // Submit continuation
  await page.fill('[data-testid="followup-textarea"]', 'What about 3+3?')
  await page.click('[data-testid="followup-submit"]')

  // Should stay on the SAME task page (no navigation to new task)
  await expect(page).toHaveURL(new RegExp(`/tasks/${originalTaskId}`))

  // Wait for the continued task to complete
  await page.waitForFunction(() => {
    return document.querySelector('[data-testid="task-status"]')?.textContent === 'COMPLETED'
  }, { timeout: 60000 })
})
