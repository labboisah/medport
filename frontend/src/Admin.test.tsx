import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { axe } from 'vitest-axe'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminWorkspace } from './AdminWorkspace'
import { NotificationsView } from './Notifications'

function mockApi(routes: Record<string, () => unknown>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    calls.push({ key, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body })
    const out = routes[key]
    return new Response(JSON.stringify(out ? out() : { data: [] }), { status: key.startsWith('POST') ? 201 : 200 })
  })
  return calls
}

afterEach(() => {
  window.location.hash = ''
  vi.restoreAllMocks()
})

const list = {
  data: [
    { id: 'n1', event: 'access_requested', message: 'A facility has asked to see some of your records.', created_at: '2026-01-04T10:00:00Z', read: false },
    { id: 'n2', event: 'security_change', message: 'Your sign-in details changed.', created_at: '2026-01-03T10:00:00Z', read: true },
  ],
  meta: { unread: 1 },
}

describe('notifications', () => {
  it('lists messages, marks all read and keeps security notices on', async () => {
    const calls = mockApi({
      'GET /notifications/preferences': () => ({ data: [
        { event: 'access_requested', in_app: true, can_disable: true },
        { event: 'security_change', in_app: true, can_disable: false },
      ], meta: { note: 'Security notices cannot be turned off.' } }),
    })
    const reload = vi.fn(async () => {})
    const { container } = render(<NotificationsView list={list} reload={reload} loaded />)
    expect(await screen.findByText(/has asked to see some of your records/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Mark all as read/ }))
    await waitFor(() => expect(calls.some((c) => c.key === 'POST /notifications/read')).toBe(true))
    const prefs = await screen.findAllByRole('switch')
    expect(prefs.some((p) => p.hasAttribute('disabled'))).toBe(true)
    expect((await axe(container)).violations).toEqual([])
  })
})

describe('admin workspace', () => {
  it('shows operations metrics and retries a failed job', async () => {
    const calls = mockApi({
      'GET /admin/ops/metrics': () => ({ data: {
        queue: { pending: 2, failed: 1, lag_seconds: 5 }, sync: { dead_letter: 0, failed: 0, partial_24h: 0 }, integrations: { active: 1, disabled: 0, keys_expired: 0 }, open_incidents: 3,
        security_24h: { failed_logins: 4, denied_record_reads: 1, integration_denials: 0 }, storage: { free_percent: 50 }, database_ms: 3,
        verification_backlog: { facilities: 1, practitioners: 2 },
      } }),
      'GET /admin/ops/failed-jobs': () => ({ data: [{ id: 'job-1', queue: 'default', job: 'DeliverNotification', failed_at: '2026-01-04T10:00:00Z', exception: 'RuntimeException' }], meta: { note: 'Security notices cannot be turned off.' } }),
    })
    render(<AdminWorkspace view="overview" />)
    expect(await screen.findByText('Open incidents')).toBeInTheDocument()
    await userEvent.click(await screen.findByRole('button', { name: /Retry/ }))
    await waitFor(() => expect(calls.some((c) => c.key === 'POST /admin/ops/failed-jobs/job-1/retry')).toBe(true))
  })

  it('shows a new integration key once', async () => {
    mockApi({
      'GET /facilities': () => ({ data: [{ id: 'fac-1', name: 'City Clinic' }] }),
      'POST /admin/integrations': () => ({ data: { id: 'i1', name: 'Sandbox feed', api_key: 'mp_sandbox_secret123' } }),
    })
    render(<AdminWorkspace view="integrations" />)
    const form = await screen.findByRole('form', { name: 'New integration' })
    await userEvent.selectOptions(within(form).getByLabelText('Facility'), 'City Clinic')
    await userEvent.type(within(form).getByLabelText('Name'), 'Sandbox feed')
    await userEvent.click(within(form).getAllByRole('checkbox')[0])
    await userEvent.click(within(form).getByRole('button', { name: /Create/ }))
    expect(await screen.findByText(/mp_sandbox_secret123/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /copied/i }))
    await waitFor(() => expect(screen.queryByText(/mp_sandbox_secret123/)).not.toBeInTheDocument())
  })
})