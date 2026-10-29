import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { TimelineView } from './Timeline'
import type { Timeline } from './api'

afterEach(() => vi.restoreAllMocks())

describe('MediPass screens', () => {
  it('asks for sign-in first and shows a plain-language error on failure', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ error: { message: 'Unauthorized' } }), { status: 401 }))
    render(<App />)

    await userEvent.type(screen.getByLabelText('Email'), 'a@example.test')
    await userEvent.type(screen.getByLabelText('Password'), 'wrong-password')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('sign-in is invalid or has expired')
  })

  it('shows provenance, staleness, disputes and the incomplete-timeline notice', () => {
    const timeline: Timeline = {
      meta: { complete: false, note: 'Only shared records appear.', sources: ['Source Hospital'], stale_after_days: 365 },
      data: [{
        id: '1', type: 'allergy', category: 'allergies', local_display: 'Penicillin',
        source: { facility: 'Source Hospital', system: null, author: null }, origin: 'patient_entered',
        clinical_date: '2020-01-01T00:00:00Z', ingested_at: '2020-01-02T00:00:00Z',
        verification_status: 'patient_entered', stale: true, correction_open: true,
      }],
    }
    render(<TimelineView timeline={timeline} />)

    expect(screen.getByRole('note')).toHaveTextContent('may be incomplete')
    expect(screen.getByText('Entered by the patient')).toBeInTheDocument()
    expect(screen.getByText('Patient-entered, not verified')).toBeInTheDocument()
    expect(screen.getByText(/Older than 365 days/)).toBeInTheDocument()
    expect(screen.getByText(/disputed this record/)).toBeInTheDocument()
  })
})
