import { test, expect } from '@playwright/test'

test('follow-up: run task to completion, submit follow-up, verify continuation', async ({ page }) => {
  await page.goto('http://localhost:8080/agents/1')

  // Submit a task
  await page.fill('[data-testid="composer-textarea"]', 'What is 2+2?')
  await page.click('[data-testid="composer-submit"]')

  // Wait for completion
  await page.waitForFunction(() => {
    return document.querySelector('[data-testid="task-status"]')?.textContent === 'COMPLETED'
  }, { timeout: 60000 })

  // Submit follow-up
  await page.fill('[data-testid="followup-textarea"]', 'What about 3+3?')
  await page.click('[data-testid="followup-submit"]')

  // Should navigate to new task
  await expect(page).toHaveURL(/\/tasks\/\d+/)

  // Verify new task shows the original question in context
  await page.waitForSelector('[data-testid="task-status"]')
})