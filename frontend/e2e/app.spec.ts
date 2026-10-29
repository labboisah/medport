import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

const json = (data: unknown, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(data) })

const timeline = {
  meta: { complete: false, note: 'Only shared records appear.', sources: [], stale_after_days: 365 },
  data: [
    { id: 'obs-1', type: 'allergy', category: 'allergies', local_display: 'Penicillin', source: { facility: 'Source Hospital', system: null, author: null }, origin: 'source_system', clinical_date: '2025-01-02', ingested_at: '2025-01-03', verification_status: 'source_verified', stale: true, correction_open: true },
  ],
}

// The whole API is stubbed so these tests exercise the real UI in a real browser without a backend or any patient data.
async function stubApi(page: Page, accountType: 'patient' | 'practitioner') {
  let pending = true
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request()
    const path = new URL(request.url()).pathname.replace('/api/v1', '')
    const key = `${request.method()} ${path}`
    if (key === 'POST /auth/login') return route.fulfill(json({ data: { token: 't', account_type: accountType } }))
    if (key === 'GET /patient/timeline') return route.fulfill(json(timeline))
    if (key === 'GET /patient/access-requests') {
      return route.fulfill(json({ data: pending ? [{ id: 'r1', facility: 'City Clinic', requested_by: 'Dr Ada', categories: ['allergies'], reason: 'Visit today', requested_hours: 24, status: 'pending' }] : [] }))
    }
    if (key === 'POST /patient/access-requests/r1/approve') {
      pending = false
      return route.fulfill(json({ data: {} }))
    }
    if (key === 'GET /facilities') return route.fulfill(json({ data: [{ id: 'f1', name: 'City Clinic' }] }))
    if (key === 'GET /provider/referrals/incoming') return route.fulfill(json({ data: [] }))
    if (key === 'POST /patients/search') return route.fulfill(json({ data: [{ id: 'p1', given_name: 'Amina', family_name: 'Test', birth_year: '1990', identity_verification: 'verified' }] }))
    return route.fulfill(json({ data: [] }))
  })
}

async function signIn(page: Page) {
  await page.goto('/')
  await page.getByLabel('Email').fill('user@example.test')
  await page.getByLabel('Password').fill('not-a-real-password')
  await page.getByRole('button', { name: 'Sign in' }).click()
}

const audit = (page: Page) => new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()

test('sign-in screen passes the automated WCAG A/AA audit, including colour contrast', async ({ page }) => {
  await page.goto('/')
  expect((await audit(page)).violations).toEqual([])
})

test('patient can approve a request using only the keyboard, and the screen passes the audit', async ({ page }) => {
  await stubApi(page, 'patient')
  await signIn(page)
  await expect(page.getByText('1 request to see your records is waiting for you.')).toBeVisible()
  expect((await audit(page)).violations).toEqual([])

  const allow = page.getByRole('button', { name: 'Allow access' })
  for (let i = 0; i < 30 && !(await allow.evaluate((el) => el === document.activeElement)); i++) await page.keyboard.press('Tab')
  await expect(allow).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page.getByText('Nothing needs your attention.')).toBeVisible()
})

test('patient screen keeps the last data and warns when the connection drops', async ({ page, context }) => {
  await stubApi(page, 'patient')
  await signIn(page)
  await expect(page.getByText('Penicillin')).toBeVisible()
  await context.setOffline(true)
  await expect(page.getByText(/You are offline\./)).toBeVisible()
  await expect(page.getByText('Penicillin')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Allow access' })).toBeDisabled()
})

test('provider workspace passes the audit with the facility forms open', async ({ page }) => {
  await stubApi(page, 'practitioner')
  await signIn(page)
  await page.getByLabel('Identifier type').fill('national_id')
  await page.getByLabel('Identifier value').fill('123')
  await page.getByRole('button', { name: 'Search' }).click()
  await expect(page.getByRole('form', { name: 'Record a visit' })).toBeVisible()
  expect((await audit(page)).violations).toEqual([])
})