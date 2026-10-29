import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

const json = (data: unknown, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(data) })

const metrics = {
  database_ms: 3, queue: { pending: 0, lag_seconds: 0, failed: 1 }, sync: { failed: 0, dead_letter: 0, partial_24h: 0 },
  integrations: { active: 1, disabled: 0, keys_expired: 0 }, security_24h: { failed_logins: 2, denied_record_reads: 0, integration_denials: 0 },
  storage: { free_percent: 60 }, verification_backlog: { facilities: 1, practitioners: 0 }, open_incidents: 1,
}

async function stub(page: Page, accountType: 'patient' | 'admin') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request()
    const key = `${request.method()} ${new URL(request.url()).pathname.replace('/api/v1', '')}`
    if (key === 'POST /auth/login') return route.fulfill(json({ data: { token: 't', account_type: accountType } }))
    if (key === 'GET /admin/ops/metrics') return route.fulfill(json({ data: metrics }))
    if (key === 'GET /admin/ops/failed-jobs') return route.fulfill(json({ data: [{ id: 'j1', queue: 'default', job: 'DeliverNotification', exception: 'RuntimeException', failed_at: '2026-01-04T10:00:00Z' }] }))
    if (key === 'GET /notifications') {
      return route.fulfill(json({ data: [{ id: 'n1', event: 'access_requested', message: 'A facility has asked to see some of your records.', created_at: '2026-01-04T10:00:00Z', read: false }], meta: { unread: 1 } }))
    }
    if (key === 'GET /notifications/preferences') {
      return route.fulfill(json({ data: [{ event: 'access_requested', in_app: true, can_disable: true }, { event: 'security_change', in_app: true, can_disable: false }], meta: { note: 'Security notices cannot be turned off.' } }))
    }
    return route.fulfill(json({ data: [] }))
  })
  await page.goto('/')
  await page.getByLabel('Email').fill('user@example.test')
  await page.getByLabel('Password').fill('not-a-real-password')
  await page.getByRole('button', { name: 'Sign in' }).click()
}

const audit = (page: Page) => new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
// Returns the elements that stick out past the viewport; empty means the page reflows.
const overflows = (page: Page) => page.evaluate(() => {
  const w = document.documentElement.clientWidth
  if (document.documentElement.scrollWidth <= w) return []
  return [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > w + 1 && !e.parentElement?.closest('.overflow-x-auto')).slice(0, 6).map((e) => `${e.tagName}.${String(e.className).slice(0, 50)}`)
})

test('admin operations and integrations screens pass the WCAG audit', async ({ page }) => {
  test.setTimeout(120_000)
  await stub(page, 'admin')
  await expect(page.getByText('Open incidents')).toBeVisible()
  expect((await audit(page)).violations).toEqual([])
  for (const view of ['integrations', 'incidents', 'policy', 'audit']) {
    await page.evaluate((v) => { window.location.hash = `#/${v}` }, view)
    await expect(page.getByRole('main')).toBeVisible()
    await page.waitForTimeout(400)
    expect((await audit(page)).violations, view).toEqual([])
  }
})

test('notifications show an unread badge, and the screen passes the audit', async ({ page }) => {
  await stub(page, 'patient')
  await expect(page.getByRole('navigation').getByText('1 unread').first()).toBeAttached()
  await page.evaluate(() => { window.location.hash = '#/notifications' })
  await expect(page.getByText('A facility has asked to see some of your records.')).toBeVisible()
  expect((await audit(page)).violations).toEqual([])
})

test('screens reflow at a 320px viewport and at 200% zoom without horizontal scrolling', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 })
  await stub(page, 'admin')
  await expect(page.getByText('Open incidents')).toBeVisible()
  expect(await overflows(page)).toEqual([])
  await page.evaluate(() => { window.location.hash = '#/audit' })
  await page.waitForTimeout(500)
  expect(await overflows(page)).toEqual([])
})

test('the access token is never written to browser storage', async ({ page }) => {
  await stub(page, 'patient')
  await expect(page.getByRole('main')).toBeVisible()
  const stored = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }) + document.cookie)
  expect(stored).not.toContain('"t"')
  expect(stored).not.toMatch(/token/i)
})