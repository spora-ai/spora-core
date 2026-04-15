import { test, expect } from '@playwright/test'

test('task lifecycle: create, wait for completion, verify notification', async ({ page }) => {
  await page.goto('http://localhost:8080')
  // Login flow (adjust selectors to match your auth UI)
  await page.fill('[data-testid="email-input"]', 'test@example.com')
  await page.fill('[data-testid="password-input"]', 'password')
  await page.click('[data-testid="login-button"]')

  // Navigate to an agent
  await page.click('[data-testid="agent-item"]')

  // Submit a prompt
  await page.fill('[data-testid="composer-textarea"]', 'Hello, what is 2+2?')
  await page.click('[data-testid="composer-submit"]')

  // Should navigate to task page
  await expect(page).toHaveURL(/\/tasks\/\d+/)

  // Wait for completion (up to 60s)
  await page.waitForFunction(() => {
    const status = document.querySelector('[data-testid="task-status"]')
    return status?.textContent === 'COMPLETED' || status?.textContent === 'FAILED'
  }, { timeout: 60000 })

  // Verify notification appeared
  const bell = page.locator('[data-testid="notification-bell"]')
  await expect(bell).toBeVisible()
})