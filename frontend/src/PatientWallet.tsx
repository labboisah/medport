import { useCallback, useEffect, useState } from 'react'
import { api, type Timeline } from './api'
import { TimelineView } from './Timeline'

type AccessRequest = { id: string; facility: string; requested_by: string | null; categories: string[]; reason: string; requested_hours: number; status: string }
type ConsentRow = { id: string; facility: string; categories: string[]; status: string; expires_at: string }

export function PatientWallet() {
  const [timeline, setTimeline] = useState<Timeline | null>(null)
  const [requests, setRequests] = useState<AccessRequest[]>([])
  const [consents, setConsents] = useState<ConsentRow[]>([])
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const [t, r, c] = await Promise.all([
        api<Timeline>('/patient/timeline'),
        api<{ data: AccessRequest[] }>('/patient/access-requests'),
        api<{ data: ConsentRow[] }>('/patient/consents'),
      ])
      setTimeline(t)
      setRequests(r.data)
      setConsents(c.data)
      setError(null)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const act = async (path: string) => {
    try {
      await api(path, { method: 'POST' })
      await load()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  const pending = requests.filter((r) => r.status === 'pending')

  return (
    <div className="space-y-8">
      {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-900">{error}</p>}

      <section aria-labelledby="requests-h">
        <h2 id="requests-h" className="text-xl font-semibold">Requests to see your records</h2>
        {pending.length === 0 ? <p className="mt-2 text-slate-700">No requests are waiting for you.</p> : (
          <ul className="mt-3 space-y-3">
            {pending.map((r) => (
              <li key={r.id} className="rounded border border-slate-300 bg-white p-4">
                <p><strong>{r.requested_by ?? 'A clinician'}</strong> at <strong>{r.facility}</strong> is asking to see: {r.categories.join(', ')}.</p>
                <p className="text-sm text-slate-700">Reason: {r.reason}. Access would last up to {r.requested_hours} hours. Nothing is shared unless you approve.</p>
                <div className="mt-3 flex gap-3">
                  <button className="rounded bg-emerald-700 px-4 py-2 text-white" onClick={() => act(`/patient/access-requests/${r.id}/approve`)}>Allow access</button>
                  <button className="rounded border border-slate-500 px-4 py-2" onClick={() => act(`/patient/access-requests/${r.id}/deny`)}>Deny</button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="sharing-h">
        <h2 id="sharing-h" className="text-xl font-semibold">Who can see your records</h2>
        {consents.length === 0 ? <p className="mt-2 text-slate-700">You have not shared your records with anyone.</p> : (
          <ul className="mt-3 space-y-2">
            {consents.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-300 bg-white p-3">
                <span>{c.facility}: {c.categories.join(', ')} — <em>{c.status}</em>, until {new Date(c.expires_at).toLocaleString()}</span>
                {c.status === 'active' && <button className="rounded border border-red-700 px-3 py-1 text-red-800" onClick={() => act(`/patient/consents/${c.id}/revoke`)}>Stop sharing</button>}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="records-h">
        <h2 id="records-h" className="text-xl font-semibold">Your records</h2>
        {timeline ? <TimelineView timeline={timeline} /> : !error && <p>Loading…</p>}
      </section>
    </div>
  )
}
