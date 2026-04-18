import { test, expect } from '@playwright/test'

test('tool approval: enable tool requiring approval, trigger it, approve via UI', async ({ page }) => {
  // Enable a tool that requires approval for this agent
  // Navigate to agent settings → tools
  await page.goto('http://localhost:8080/agents/1/settings')
  await page.click('[data-testid="tool-item-some-tool"] [data-testid="enable-toggle"]')

  // Go to agent page
  await page.goto('http://localhost:8080/agents/1')
  await page.fill('[data-testid="composer-textarea"]', 'Search the web for latest AI news')
  await page.click('[data-testid="composer-submit"]')

  // Should hit pending approval
  const approvalBar = page.locator('[data-testid="approval-bar"]')
  await expect(approvalBar).toBeVisible({ timeout: 30000 })

  // Approve the tool call
  await page.click('[data-testid="approval-bar"] [data-testid="approve-button"]')

  // Task should resume and eventually complete
  await page.waitForFunction(() => {
    return document.querySelector('[data-testid="task-status"]')?.textContent === 'COMPLETED'
  }, { timeout: 60000 })
})