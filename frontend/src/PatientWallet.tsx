import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, type Timeline } from './api'
import { CorrectionForm, DownloadButton } from './RecordActions'
import { TimelineView } from './Timeline'
import { CategoryPicker, Messages, OfflineBanner, Section } from './ui'
import { inputClass, label, primaryButton, useAction, useFacilities, useOnline } from './hooks'

type AccessRequest = { id: string; facility: string; requested_by: string | null; categories: string[]; reason: string; requested_hours: number; status: string }
type ConsentRow = { id: string; facility: string; categories: string[]; status: string; expires_at: string }
type HistoryRow = { occurred_at: string; action: string; outcome: string; resource_type: string; purpose: string | null; facility: string | null; emergency: boolean }

const POLL_MS = 60_000

const actionText = (h: HistoryRow) => {
  const who = h.facility ?? 'A facility'
  const what = h.resource_type.replace(/_/g, ' ')
  if (h.outcome !== 'success') return `${who} tried to use your ${what} records and was not allowed.`
  return `${who}: ${h.action.replace(/_/g, ' ')} (${what}).`
}

export function PatientWallet() {
  const [timeline, setTimeline] = useState<Timeline | null>(null)
  const [requests, setRequests] = useState<AccessRequest[]>([])
  const [consents, setConsents] = useState<ConsentRow[]>([])
  const [history, setHistory] = useState<HistoryRow[]>([])
  const [lastLoaded, setLastLoaded] = useState<Date | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const online = useOnline()
  const facilities = useFacilities()
  const act = useAction()

  const [facilityId, setFacilityId] = useState('')
  const [categories, setCategories] = useState<string[]>([])
  const [hours, setHours] = useState(24)

  const load = useCallback(async () => {
    try {
      const [t, r, c, h] = await Promise.all([
        api<Timeline>('/patient/timeline'),
        api<{ data: AccessRequest[] }>('/patient/access-requests'),
        api<{ data: ConsentRow[] }>('/patient/consents'),
        api<{ data: HistoryRow[] }>('/patient/access-history'),
      ])
      setTimeline(t)
      setRequests(r.data)
      setConsents(c.data)
      setHistory(h.data)
      setLastLoaded(new Date())
      setLoadError(null)
    } catch (e) {
      // Keep showing the last data that loaded rather than blanking the screen on a weak connection.
      setLoadError((e as Error).message)
    }
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  useEffect(() => {
    if (!online) return
    const timer = setInterval(() => void load(), POLL_MS)
    const onVisible = () => document.visibilityState === 'visible' && void load()
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('online', onVisible)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('online', onVisible)
    }
  }, [online, load])

  const pending = requests.filter((r) => r.status === 'pending')

  // Notification text is deliberately generic: no clinic or clinical detail in the tab title.
  useEffect(() => {
    document.title = pending.length > 0 ? `(${pending.length}) MediPass` : 'MediPass'
  }, [pending.length])

  const decide = (path: string, done: string) => act.run(async () => {
    await api(path, { method: 'POST' })
    await load()
    return done
  })

  const share = (e: FormEvent) => {
    e.preventDefault()
    void act.run(async () => {
      await api('/patient/consents', { body: { facility_id: facilityId, categories, duration_hours: hours } })
      setCategories([])
      await load()
      return 'Sharing started. You can stop it at any time below.'
    })
  }

  return (
    <div className="space-y-8">
      <OfflineBanner online={online} lastLoaded={lastLoaded} />
      {loadError && online && (
        <p role="alert" className="rounded bg-red-50 p-3 text-red-900">
          {loadError} <button className="underline" onClick={() => void load()}>Try again</button>
        </p>
      )}
      <Messages error={act.error} message={act.message} />

      <p aria-live="polite" className="rounded border border-slate-300 bg-white p-3">
        {pending.length === 0 ? 'Nothing needs your attention.' : `${pending.length} request${pending.length === 1 ? '' : 's'} to see your records ${pending.length === 1 ? 'is' : 'are'} waiting for you.`}
      </p>

      <Section id="requests-h" title="Requests to see your records">
        {pending.length === 0 ? <p className="text-slate-700">No requests are waiting for you.</p> : (
          <ul className="space-y-3">
            {pending.map((r) => (
              <li key={r.id} className="rounded border border-slate-300 bg-white p-4">
                <p><strong>{r.requested_by ?? 'A clinician'}</strong> at <strong>{r.facility}</strong> is asking to see: {r.categories.map(label).join(', ')}.</p>
                <p className="text-sm text-slate-700">Reason: {r.reason}. Access would last up to {r.requested_hours} hours. Nothing is shared unless you approve.</p>
                <div className="mt-3 flex gap-3">
                  <button className="rounded bg-emerald-800 px-4 py-2 text-white disabled:opacity-60" disabled={!online || act.busy} onClick={() => decide(`/patient/access-requests/${r.id}/approve`, 'Access allowed.')}>Allow access</button>
                  <button className="rounded border border-slate-700 px-4 py-2 disabled:opacity-60" disabled={!online || act.busy} onClick={() => decide(`/patient/access-requests/${r.id}/deny`, 'Request denied.')}>Deny</button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section id="share-h" title="Share your records with a facility">
        <form onSubmit={share} className="space-y-3" aria-label="Share your records">
          <label className="block">Facility
            <select className={inputClass} value={facilityId} onChange={(e) => setFacilityId(e.target.value)} required>
              <option value="">Choose a facility</option>
              {facilities.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </select>
          </label>
          <CategoryPicker legend="What they may see" value={categories} onChange={setCategories} />
          <label className="block">For how many hours
            <input type="number" min={1} max={720} className={inputClass} value={hours} onChange={(e) => setHours(Number(e.target.value))} required />
          </label>
          <button className={primaryButton} disabled={!online || act.busy || categories.length === 0 || !facilityId}>Start sharing</button>
        </form>
      </Section>

      <Section id="sharing-h" title="Who can see your records">
        {consents.length === 0 ? <p className="text-slate-700">You have not shared your records with anyone.</p> : (
          <ul className="space-y-2">
            {consents.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-300 bg-white p-3">
                <span>{c.facility}: {c.categories.map(label).join(', ')} — <em>{c.status}</em>, until {new Date(c.expires_at).toLocaleString()}</span>
                {c.status === 'active' && <button className="rounded border border-red-800 px-3 py-1 text-red-900 disabled:opacity-60" disabled={!online || act.busy} onClick={() => decide(`/patient/consents/${c.id}/revoke`, 'Sharing stopped.')}>Stop sharing</button>}
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section id="history-h" title="Who has looked at your records">
        {history.length === 0 ? <p className="text-slate-700">No one has looked at your records yet.</p> : (
          <ol className="space-y-2">
            {history.map((h, i) => (
              <li key={i} className="rounded border border-slate-300 bg-white p-3 text-sm">
                <time dateTime={h.occurred_at}>{new Date(h.occurred_at).toLocaleString()}</time> — {actionText(h)}{h.emergency ? ' (emergency access)' : ''}
              </li>
            ))}
          </ol>
        )}
      </Section>

      <Section id="timeline-h" title="Your records">
        {timeline ? (
          <TimelineView
            timeline={timeline}
            actions={(item) => (
              <>
                <DownloadButton item={item} />
                {!item.correction_open && <CorrectionForm item={item} onDone={() => void load()} />}
              </>
            )}
          />
        ) : <p>Loading your records…</p>}
      </Section>
    </div>
  )
}
