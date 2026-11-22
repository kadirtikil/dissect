import { test, expect } from '@playwright/test'

/**
 * The Routes page's OpenAPI download.
 *
 * Only the package can build the document, so the standalone dev server has
 * no URL for it, and the page offers nothing rather than a broken link.
 */

test('the standalone page offers no OpenAPI file', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await expect(page.getByText(/endpoints/)).toBeVisible()
  await expect(page.getByTestId('openapi-download')).toHaveCount(0)
})

test('under the package, the Routes page offers the document as a file', async ({ page }) => {
  await page.addInitScript(() => {
    window.__DISSECT__ = { openapiUrl: '/dissect/openapi.json' }
  })

  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  const link = page.getByTestId('openapi-download')

  await expect(link).toHaveAttribute('href', '/dissect/openapi.json')
  await expect(link).toHaveAttribute('download', 'openapi.json')
  await expect(link).toHaveText('OpenAPI')

  // It belongs to the Routes page alone.
  await page.getByRole('button', { name: 'Jobs', exact: true }).click()
  await expect(link).toHaveCount(0)
})
