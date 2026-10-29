import { useState, type FormEvent } from 'react'
import { api, type Timeline } from './api'
import { TimelineView } from './Timeline'

type Match = { id: string; given_name: string; family_name: string; birth_year: string | null; identity_verification: string }

const CATEGORIES = ['encounters', 'conditions', 'medications', 'allergies', 'observations', 'diagnostic_reports', 'documents', 'referrals']

export function ProviderWorkspace() {
  const [system, setSystem] = useState('')
  const [value, setValue] = useState('')
  const [match, setMatch] = useState<Match | null>(null)
  const [searched, setSearched] = useState(false)
  const [timeline, setTimeline] = useState<Timeline | null>(null)
  const [categories, setCategories] = useState<string[]>(['allergies'])
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const run = async (fn: () => Promise<void>) => {
    setError(null)
    setMessage(null)
    try {
      await fn()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  const search = (e: FormEvent) => {
    e.preventDefault()
    void run(async () => {
      const res = await api<{ data: Match[] }>('/patients/search', { body: { system, value } })
      setMatch(res.data[0] ?? null)
      setSearched(true)
      setTimeline(null)
    })
  }

  const open = () => run(async () => {
    setTimeline(await api<Timeline>(`/provider/patients/${match!.id}/timeline`))
  })

  const request = (e: FormEvent) => {
    e.preventDefault()
    void run(async () => {
      await api('/provider/access-requests', { body: { patient_id: match!.id, categories, reason, duration_hours: 24 } })
      setMessage('Request sent. You will only see records the patient chooses to share.')
    })
  }

  return (
    <div className="space-y-8">
      <p className="rounded border border-slate-300 bg-white p-3 text-sm">Purpose of access: <strong>direct care</strong>. Every view is recorded in the patient’s access history.</p>
      {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-900">{error}</p>}
      {message && <p role="status" className="rounded bg-emerald-50 p-3 text-emerald-900">{message}</p>}

      <form onSubmit={search} className="space-y-3" aria-label="Find a patient">
        <h2 className="text-xl font-semibold">Find a patient by identifier</h2>
        <label className="block">Identifier type<input className="mt-1 block w-full rounded border border-slate-400 p-2" value={system} onChange={(e) => setSystem(e.target.value)} required /></label>
        <label className="block">Identifier value<input className="mt-1 block w-full rounded border border-slate-400 p-2" value={value} onChange={(e) => setValue(e.target.value)} required /></label>
        <button className="rounded bg-slate-900 px-4 py-2 text-white">Search</button>
      </form>

      {searched && !match && <p>No patient matches that identifier. Check it and try again.</p>}

      {match && (
        <section aria-label="Matched patient" className="space-y-4">
          <p className="rounded border border-slate-300 bg-white p-3">
            {match.given_name} {match.family_name}, born {match.birth_year ?? 'unknown'} — identity {match.identity_verification === 'verified' ? 'verified' : 'not verified'}
          </p>
          <button className="rounded bg-slate-900 px-4 py-2 text-white" onClick={open}>Open shared records</button>
          <form onSubmit={request} className="space-y-3" aria-label="Request access">
            <h2 className="text-xl font-semibold">Ask the patient for access</h2>
            <fieldset>
              <legend className="font-medium">Records you need</legend>
              {CATEGORIES.map((c) => (
                <label key={c} className="mr-4 inline-block">
                  <input type="checkbox" checked={categories.includes(c)} onChange={(e) => setCategories(e.target.checked ? [...categories, c] : categories.filter((x) => x !== c))} /> {c.replace(/_/g, ' ')}
                </label>
              ))}
            </fieldset>
            <label className="block">Reason<input className="mt-1 block w-full rounded border border-slate-400 p-2" value={reason} onChange={(e) => setReason(e.target.value)} required minLength={5} /></label>
            <button className="rounded border border-slate-700 px-4 py-2" disabled={categories.length === 0}>Send request</button>
          </form>
        </section>
      )}

      {timeline && <TimelineView timeline={timeline} />}
    </div>
  )
}
