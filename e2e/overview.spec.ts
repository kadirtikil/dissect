import { test, expect } from '@playwright/test'

test('the overview says when the schema could not be read', async ({ page }) => {
  await page.route('**/schema.json', (route) => route.fulfill({ status: 500, body: 'boom' }))
  await page.goto('/')

  const problem = page.getByTestId('overview-schema-error')
  await expect(problem).toBeVisible()
  await expect(problem).toContainText('Could not load schema.json')
  await expect(problem).toContainText('500')
  await expect(page.getByText('Reading schema…')).toHaveCount(0)
})
