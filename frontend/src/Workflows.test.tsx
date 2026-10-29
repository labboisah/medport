import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { axe } from 'vitest-axe'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { PatientWallet } from './PatientWallet'
import { ProviderWorkspace } from './ProviderWorkspace'

type Handler = (body: unknown, init: RequestInit) => { status?: number; json: unknown }

const timeline = {
  meta: { complete: false, note: 'Only shared records appear.', sources: [], stale_after_days: 365 },
  data: [
    { id: 'doc-1', type: 'document_reference', category: 'documents', title: 'Discharge note', source: { facility: 'Source Hospital', system: null, author: null }, origin: 'source_system', clinical_date: '2026-01-02', ingested_at: '2026-01-03', verification_status: 'source_verified', stale: false, correction_open: false },
  ],
}

function mockApi(routes: Record<string, Handler>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const path = String(input).replace('/api/v1', '')
    const key = `${init.method ?? 'GET'} ${path}`
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : init.body
    calls.push({ key, body })
    const handler = routes[key]
    if (!handler) return new Response(JSON.stringify({ data: [] }), { status: 200 })
    const out = handler(body, init)
    return new Response(JSON.stringify(out.json), { status: out.status ?? 200 })
  })
  return calls
}

const patientRoutes: Record<string, Handler> = {
  'GET /patient/timeline': () => ({ json: timeline }),
  'GET /patient/access-requests': () => ({ json: { data: [{ id: 'req-1', facility: 'City Clinic', requested_by: 'Dr Ada', categories: ['allergies'], reason: 'Visit today', requested_hours: 24, status: 'pending' }] } }),
  'GET /patient/consents': () => ({ json: { data: [] } }),
  'GET /patient/access-history': () => ({ json: { data: [{ occurred_at: '2026-01-04T10:00:00Z', action: 'read', outcome: 'success', resource_type: 'allergy', purpose: 'direct_care', facility: 'City Clinic', emergency: false }] } }),
  'GET /facilities': () => ({ json: { data: [{ id: 'fac-1', name: 'City Clinic' }] } }),
}

afterEach(() => {
  vi.restoreAllMocks()
  Object.defineProperty(navigator, 'onLine', { value: true, configurable: true })
})

describe('patient screens', () => {
  it('shows notification, history and has no automatically detectable accessibility violations', async () => {
    mockApi(patientRoutes)
    const { container } = render(<PatientWallet />)

    expect(await screen.findByText('1 request to see your records is waiting for you.')).toBeInTheDocument()
    await waitFor(() => expect(document.title).toBe('(1) MediPass'))
    expect(await screen.findByText(/City Clinic: read \(allergy\)/)).toBeInTheDocument()
    expect((await axe(container)).violations).toEqual([])
  })

  it('can be operated with the keyboard alone: approve a request', async () => {
    const calls = mockApi({ ...patientRoutes, 'POST /patient/access-requests/req-1/approve': () => ({ json: { data: {} } }) })
    render(<PatientWallet />)
    const allow = await screen.findByRole('button', { name: 'Allow access' })

    await userEvent.tab()
    let guard = 0
    while (document.activeElement !== allow && guard++ < 20) await userEvent.tab()
    expect(allow).toHaveFocus()
    await userEvent.keyboard('{Enter}')

    expect(await screen.findByText('Access allowed.')).toBeInTheDocument()
    expect(calls.some((c) => c.key === 'POST /patient/access-requests/req-1/approve')).toBe(true)
  })

  it('shares with a facility, and reports a problem with a record', async () => {
    const calls = mockApi({
      ...patientRoutes,
      'POST /patient/consents': () => ({ status: 201, json: { data: {} } }),
      'POST /patient/records/documents/doc-1/corrections': () => ({ status: 201, json: { data: {} } }),
    })
    render(<PatientWallet />)

    await userEvent.selectOptions(await screen.findByLabelText('Facility'), 'City Clinic')
    await userEvent.click(screen.getByLabelText('allergies'))
    await userEvent.click(screen.getByRole('button', { name: 'Start sharing' }))
    await screen.findByText(/Sharing started/)
    expect(calls.find((c) => c.key === 'POST /patient/consents')?.body).toEqual({ facility_id: 'fac-1', categories: ['allergies'], duration_hours: 24 })

    await userEvent.click(screen.getByRole('button', { name: 'Report a problem with this record' }))
    await userEvent.type(screen.getByLabelText('Explain in your own words'), 'This is not mine')
    await userEvent.click(screen.getByRole('button', { name: 'Send' }))
    await screen.findByText(/Your request was sent/)
    expect(calls.find((c) => c.key.includes('/corrections'))?.body).toEqual({ kind: 'dispute', reason: 'This is not mine' })
  })

  it('tells the user when offline, keeps the last data and disables changes', async () => {
    mockApi(patientRoutes)
    render(<PatientWallet />)
    await screen.findByText('1 request to see your records is waiting for you.')

    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true })
    act(() => {
      window.dispatchEvent(new Event('offline'))
    })

    expect(await screen.findByText(/You are offline\./)).toHaveTextContent('Showing what was last loaded')
    expect(screen.getByRole('button', { name: 'Allow access' })).toBeDisabled()
    expect(screen.getAllByText('Discharge note', { exact: false }).length).toBeGreaterThan(0)
  })
})

describe('facility screens', () => {
  const providerRoutes: Record<string, Handler> = {
    'POST /patients/search': () => ({ json: { data: [{ id: 'pat-1', given_name: 'Amina', family_name: 'Test', birth_year: '1990', identity_verification: 'verified' }] } }),
    'GET /facilities': () => ({ json: { data: [{ id: 'fac-2', name: 'Referral Hospital' }] } }),
    'GET /provider/referrals/incoming': () => ({ json: { data: [{ id: 'ref-1', from_facility: 'City Clinic', reason: 'Chest pain', status: 'requested', outcome: null, patient: { given_name: 'Amina', family_name: 'Test' } }] } }),
  }

  const findPatient = async () => {
    await userEvent.type(screen.getByLabelText('Identifier type'), 'national_id')
    await userEvent.type(screen.getByLabelText('Identifier value'), '123')
    await userEvent.click(screen.getByRole('button', { name: 'Search' }))
    await screen.findByLabelText('Matched patient')
  }

  it('asks before saving a possible duplicate visit, then saves with confirmation', async () => {
    let first = true
    const calls = mockApi({
      ...providerRoutes,
      'POST /provider/patients/pat-1/encounters': () => {
        if (first) {
          first = false
          return { status: 409, json: { error: { message: 'A similar encounter already exists for this day. Confirm to save anyway.' } } }
        }
        return { status: 201, json: { data: {} } }
      },
    })
    render(<ProviderWorkspace />)
    await findPatient()

    const form = screen.getByRole('form', { name: 'Record a visit' })
    await userEvent.type(within(form).getByLabelText('Reason for the visit'), 'Fever')
    await userEvent.click(within(form).getByRole('button', { name: 'Save visit' }))
    expect(await within(form).findByRole('alert')).toHaveTextContent('similar encounter')

    await userEvent.click(within(form).getByRole('button', { name: /Save anyway/ }))
    await within(form).findByText(/labelled as entered by staff/)
    const saves = calls.filter((c) => c.key === 'POST /provider/patients/pat-1/encounters')
    expect(saves[1].body).toMatchObject({ confirm_duplicate: true, reason: 'Fever' })
  })

  it('sends referrals, uploads documents and accepts incoming referrals', async () => {
    const calls = mockApi({
      ...providerRoutes,
      'POST /provider/patients/pat-1/referrals': () => ({ status: 201, json: { data: {} } }),
      'POST /provider/patients/pat-1/documents': () => ({ status: 201, json: { data: { scan_status: 'clean' } } }),
      'POST /provider/referrals/ref-1/decision': () => ({ json: { data: {} } }),
    })
    const { container } = render(<ProviderWorkspace />)
    await findPatient()

    const referral = screen.getByRole('form', { name: 'Refer the patient' })
    await userEvent.selectOptions(within(referral).getByLabelText('Refer to'), 'Referral Hospital')
    await userEvent.type(within(referral).getByLabelText('Reason for the referral'), 'Needs cardiology')
    await userEvent.click(within(referral).getByRole('button', { name: 'Send referral' }))
    await within(referral).findByText(/Referral sent/)

    const upload = screen.getByRole('form', { name: 'Upload a document' })
    await userEvent.type(within(upload).getByLabelText('Title'), 'Scan')
    await userEvent.upload(within(upload).getByLabelText('File'), new File(['%PDF-1.4'], 'scan.pdf', { type: 'application/pdf' }))
    await userEvent.click(within(upload).getByRole('button', { name: 'Upload' }))
    await within(upload).findByText(/Uploaded/)
    expect(calls.find((c) => c.key.endsWith('/documents'))?.body).toBeInstanceOf(FormData)

    await userEvent.click(await screen.findByRole('button', { name: 'Accept' }))
    await waitFor(() => expect(calls.find((c) => c.key.includes('/decision'))?.body).toEqual({ status: 'accepted' }))
    expect((await axe(container)).violations).toEqual([])
  })
})

describe('keyboard access', () => {
  it('offers a skip link as the first stop and labels the main landmark', async () => {
    mockApi({})
    render(<App />)
    await userEvent.tab()
    expect(screen.getByText('Skip to main content')).toHaveFocus()
    expect(screen.getByRole('main')).toBeInTheDocument()
  })
})
