import { test, expect } from '@playwright/test'

test('scheduled run: create one-shot, trigger via API, verify task created', async ({ page, request }) => {
  // Create a one-shot scheduled run via API
  const response = await request.post('http://localhost:8080/api/v1/agents/1/scheduled-runs', {
    data: {
      raw_prompt: 'What is the capital of France?',
      run_at: new Date(Date.now() + 60000).toISOString(),
      timezone: 'UTC',
    },
    headers: { Authorization: 'Bearer TEST_TOKEN' } // adjust auth
  })
  expect(response.status()).toBe(201)
  const run = await response.json()

  // Trigger immediately via the trigger endpoint
  await request.post(`http://localhost:8080/api/v1/agents/1/scheduled-runs/${run.data.id}/trigger`)

  // Verify a task was created for this agent
  await page.goto('http://localhost:8080/agents/1')
  await page.waitForFunction(() => {
    return document.querySelectorAll('[data-testid="task-item"]').length > 0
  })
})