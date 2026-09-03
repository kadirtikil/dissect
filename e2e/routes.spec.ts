import { test, expect } from '@playwright/test'

/**
 * The endpoint surface, against public/routes.json.
 *
 * Endpoints stand on their own here: the fixture carries no model ids, and the
 * thing worth testing is that the list describes each endpoint's own contract
 * and never narrows itself by anything chosen on another surface.
 */

test('opens the endpoint list on demand and groups it by controller', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await expect(page.getByText(/\d+ of \d+ endpoints/)).toBeVisible()
  await expect(page.getByRole('button', { name: /DocumentController/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /WorkspaceController/ })).toBeVisible()
})

test('narrows the list by search and by facet, and clears again', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  const rows = page.locator('li button')
  // The list is fetched when the surface opens, so counting before it lands
  // would compare everything against zero.
  await expect(rows.first()).toBeVisible()
  const total = await rows.count()

  await page.getByLabel('Filter endpoints').fill('workspaces')
  await expect(rows).toHaveCount(3)

  await page.getByLabel('Filter endpoints').fill('')
  // A bare verb filters by verb rather than by text. Two now: the plain
  // resource route and the JSON:API one.
  await page.getByRole('button', { name: /^DELETE/ }).first().click()
  await expect(rows).toHaveCount(2)

  await page.getByLabel('Clear all filters').click()
  await expect(rows).toHaveCount(total)
})

test('splits the table into the frontend half and the external one', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  const rows = page.locator('li button')
  await expect(rows.first()).toBeVisible()

  // Both halves are offered with their true size before either is picked.
  await expect(page.getByRole('button', { name: /^Web 4/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /^API 10/ })).toBeVisible()

  await page.getByRole('button', { name: /^API/ }).click()
  await expect(page.getByText('10 of 14 endpoints')).toBeVisible()
  // Stateless routes only. Asserted on the controller groupings rather than on
  // a path fragment: `api/workspaces/{workspace}/members` is an API route that
  // still contains the word the web half is named for.
  await expect(page.getByRole('button', { name: /WorkspaceController/ })).toHaveCount(0)
  await expect(page.getByRole('button', { name: /DocumentController/ })).toBeVisible()

  // The other tab still reports its real size while this one is showing, or it
  // would read as "nothing to switch to".
  await expect(page.getByRole('button', { name: /^Web 4/ })).toBeVisible()

  await page.getByRole('button', { name: /^Web/ }).click()
  await expect(page.getByText('4 of 14 endpoints')).toBeVisible()
  await expect(page.getByRole('button', { name: /DocumentController/ })).toHaveCount(0)
  await expect(page.getByRole('button', { name: /WorkspaceController/ })).toBeVisible()

  // Picking the half already showing goes back to the whole table.
  await page.getByRole('button', { name: /^Web/ }).click()
  await expect(page.getByText('14 of 14 endpoints')).toBeVisible()
})

test('describes an endpoint down to the shape of its payloads', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await page.locator('li button', { hasText: 'api/documents' }).filter({ hasText: 'POST' }).click()

  await expect(page.getByText('documents.store')).toBeVisible()
  await expect(page.getByText('App\\Http\\Requests\\StoreDocumentRequest')).toBeVisible()

  // A nested rule path arrives in the same `[]` grammar the response uses.
  await expect(page.getByText('tags[].name')).toBeVisible()
  // A rule naming a table is shown as written, not resolved to a model.
  await expect(page.getByText('exists:document_types,id')).toBeVisible()
})

test('describes a JSON:API endpoint as the envelope it actually sends', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await page.locator('li button', { hasText: 'v1/documents' }).filter({ hasText: 'POST' }).click()

  // Read from the schema, not from a return type — every route the package
  // registers runs the same generic controller.
  await expect(page.getByText('App\\JsonApi\\V1\\Documents\\DocumentSchema').first()).toBeVisible()
  await expect(page.getByText('JSON:API document').first()).toBeVisible()

  // The envelope is the point: a consumer writes `data.attributes.title`, so
  // the containers are rendered as the tree they are rather than as a column
  // of identical dotted prefixes.
  //
  // Scoped to the section: the graph stays mounted behind this surface, and a
  // bare text match resolves to one of its hidden column names.
  const request = page.locator('section').filter({ hasText: 'REQUEST' }).last()

  await expect(request.getByText('attributes', { exact: true })).toBeVisible()
  await expect(request.getByText('relationships', { exact: true })).toBeVisible()
  await expect(request.getByText('title', { exact: true })).toBeVisible()

  // Constraints come from the ResourceRequest beside the schema, keyed by
  // field name and mapped onto the path the value actually sits at.
  await expect(request.getByText('App\\JsonApi\\V1\\Documents\\DocumentRequest')).toBeVisible()
  await expect(request.getByText('max:255')).toBeVisible()

  // A 204 is an answer, not a failure to find a body.
  await page.locator('li button', { hasText: 'v1/documents/{document}' }).filter({ hasText: 'DELETE' }).click()
  await expect(page.getByText(/Answers 204 No Content/)).toBeVisible()

  // A relationship endpoint promises identifiers and nothing more.
  await page.locator('li button', { hasText: 'relationships/owner' }).click()
  await expect(page.getByText('JSON:API identifiers')).toBeVisible()

  const response = page.locator('section').filter({ hasText: 'RESPONSE' }).last()

  await expect(response.getByText('type', { exact: true })).toBeVisible()
  await expect(response.getByText('attributes', { exact: true })).toHaveCount(0)
})

test('admits when a shape was inferred rather than read from the framework', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await page.locator('li button', { hasText: 'workspaces' }).filter({ hasText: 'POST' }).click()

  // A confidently wrong endpoint description is worse than one that says so.
  await expect(page.getByText(/inferred —/)).toBeVisible()
  // A redirect has no payload; that is an answer, not a failure to look.
  await expect(page.getByText(/Redirects — no JSON body/)).toBeVisible()
})

test('shows every endpoint, and offers no way back into the graph', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  // The whole table. Nothing outside this surface narrows it: which models
  // somebody grouped into a view says nothing about which endpoints they want
  // to read, and a route going missing because of a choice made elsewhere is
  // the one failure an endpoint list must not have.
  //
  // That a saved view no longer scopes this list is covered in the filter unit
  // tests rather than here — building a view writes public/views.json, which
  // the graph specs also write, and they run in a separate worker.
  await expect(page.getByText('14 of 14 endpoints')).toBeVisible()
  await expect(page.getByRole('button', { name: /^in “/ })).toHaveCount(0)

  // No row advertises models, and no endpoint links back to one.
  await expect(page.locator('li button', { hasText: '⬡' })).toHaveCount(0)

  await page.locator('li button', { hasText: 'api/documents/{document}' }).filter({ hasText: 'GET' }).click()
  await expect(page.locator('section', { hasText: 'TOUCHES' })).toHaveCount(0)
  // The bound {document} is described as a placeholder, not as a model.
  await expect(page.getByText('bound to')).toHaveCount(0)
})

test('remembers which surface was open across a reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()
  await expect(page.getByText(/\d+ of \d+ endpoints/)).toBeVisible()

  await page.reload()

  // Per browser, deliberately not in a committed file — the same call the
  // active saved view makes.
  await expect(page.getByRole('button', { name: 'Routes', exact: true })).toHaveAttribute('aria-current', 'page')

  await page.getByRole('button', { name: 'Models', exact: true }).click()
})
