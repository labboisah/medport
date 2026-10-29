import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, type Timeline } from './api'
import { DataTable, type Column } from './DataTable'
import { label, primaryButton, useAction, useFacilities, useOnline, useView, optionsFrom } from './hooks'
import { CorrectionForm, DownloadButton } from './RecordActions'
import { RecordsTable } from './Timeline'
import { Badge, CategoryPicker, Messages, OfflineBanner, PageLoader, Panel, Stat } from './ui'

type AccessRequest = { id: string; facility: string; requested_by: string | null; categories: string[]; reason: string; requested_hours: number; status: string }
type ConsentRow = { id: string; facility: string; categories: string[]; status: string; granted_at: string; expires_at: string }
type HistoryRow = { occurred_at: string; action: string; outcome: string; resource_type: string; purpose: string | null; facility: string | null; emergency: boolean }

const POLL_MS = 60_000
const cap = (s: string) => label(s).replace(/^./, (c) => c.toUpperCase())
const when = (v: string) => new Date(v).toLocaleString()
const statusTone = (s: string) => (s === 'active' ? 'good' : s === 'revoked' ? 'bad' : 'neutral')

export function PatientWallet() {
  const view = useView('overview')
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
  const active = consents.filter((c) => c.status === 'active')

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

  const consentColumns: Column<ConsentRow>[] = [
    { key: 'facility', header: 'Facility', cell: (c) => <span className="font-medium">{c.facility}</span>, sort: (c) => c.facility },
    { key: 'categories', header: 'Records shared', cell: (c) => c.categories.map(label).join(', ') },
    { key: 'status', header: 'Status', cell: (c) => <Badge tone={statusTone(c.status)}>{cap(c.status)}</Badge>, sort: (c) => c.status },
    { key: 'until', header: 'Until', cell: (c) => when(c.expires_at), sort: (c) => c.expires_at },
    {
      key: 'action', header: 'Action', cell: (c) => c.status === 'active'
        ? <button className="btn-danger btn-sm" disabled={!online || act.busy} onClick={() => decide(`/patient/consents/${c.id}/revoke`, 'Sharing stopped.')}>Stop sharing<span className="sr-only"> with {c.facility}</span></button>
        : null,
    },
  ]

  const historyColumns: Column<HistoryRow>[] = [
    { key: 'when', header: 'When', cell: (h) => <time dateTime={h.occurred_at}>{when(h.occurred_at)}</time>, sort: (h) => h.occurred_at },
    { key: 'facility', header: 'Facility', cell: (h) => h.facility ?? 'A facility', sort: (h) => h.facility ?? '' },
    { key: 'what', header: 'Activity', cell: (h) => `${cap(h.action)} (${label(h.resource_type)})${h.emergency ? ' - emergency access' : ''}` },
    { key: 'result', header: 'Result', cell: (h) => h.outcome === 'success' ? <Badge tone="good">Allowed</Badge> : <Badge tone="bad">Not allowed</Badge>, sort: (h) => h.outcome },
  ]

  const last = history.find((h) => h.outcome === 'success')

  return (
    <div className="space-y-6">
      <OfflineBanner online={online} lastLoaded={lastLoaded} />
      {loadError && online && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">
          {loadError} <button className="underline" onClick={() => void load()}>Try again</button>
        </p>
      )}
      <Messages error={act.error} message={act.message} />

      {view === 'overview' && (
        <>
          <p aria-live="polite" className={`rounded-lg border p-4 ${pending.length ? 'border-teal-300 bg-teal-50 text-teal-900' : 'border-slate-200 bg-white text-slate-800'}`}>
            {pending.length === 0 ? 'Nothing needs your attention.' : `${pending.length} request${pending.length === 1 ? '' : 's'} to see your records ${pending.length === 1 ? 'is' : 'are'} waiting for you.`}
          </p>
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Stat label="Records" value={timeline?.data.length ?? '—'} hint="Items you can see" />
            <Stat label="Waiting for you" value={pending.length} hint="Access requests" />
            <Stat label="Sharing now" value={active.length} hint="Active facilities" />
            <Stat label="Last viewed" value={last ? new Date(last.occurred_at).toLocaleDateString() : '—'} hint={last?.facility ?? 'No views yet'} />
          </div>

          <section aria-labelledby="requests-h" className="space-y-3">
            <h2 id="requests-h" className="text-lg font-semibold">Requests to see your records</h2>
            {pending.length === 0 ? <p className="text-slate-700">No requests are waiting for you.</p> : (
              <ul className="space-y-3">
                {pending.map((r) => (
                  <li key={r.id} className="card p-4">
                    <p><strong>{r.requested_by ?? 'A clinician'}</strong> at <strong>{r.facility}</strong> is asking to see: {r.categories.map(label).join(', ')}.</p>
                    <p className="text-sm text-slate-700">Reason: {r.reason}. Access would last up to {r.requested_hours} hours. Nothing is shared unless you approve.</p>
                    <div className="mt-3 flex gap-3">
                      <button className={primaryButton} disabled={!online || act.busy} onClick={() => decide(`/patient/access-requests/${r.id}/approve`, 'Access allowed.')}>Allow access</button>
                      <button className="btn-secondary" disabled={!online || act.busy} onClick={() => decide(`/patient/access-requests/${r.id}/deny`, 'Request denied.')}>Deny</button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section aria-labelledby="recent-h" className="space-y-3">
            <h2 id="recent-h" className="text-lg font-semibold">Recent activity</h2>
            {history.length === 0 ? <p className="text-slate-700">No one has looked at your records yet.</p> : (
              <ol className="card divide-y divide-slate-200">
                {history.slice(0, 5).map((h, i) => (
                  <li key={i} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                    <span>{h.facility ?? 'A facility'}: {h.action.replace(/_/g, ' ')} ({label(h.resource_type)})</span>
                    <time dateTime={h.occurred_at} className="text-slate-700">{when(h.occurred_at)}</time>
                  </li>
                ))}
              </ol>
            )}
          </section>
        </>
      )}

      {view === 'records' && (
        timeline ? (
          <RecordsTable timeline={timeline} actions={(item) => (
            <>
              <DownloadButton item={item} />
              {!item.correction_open && <CorrectionForm item={item} onDone={() => void load()} />}
            </>
          )} />
        ) : <PageLoader text="Loading your records…" />
      )}

      {view === 'sharing' && (
        <>
          <Panel>
            <h2 className="text-lg font-semibold">Share your records with a facility</h2>
            <form onSubmit={share} className="space-y-3" aria-label="Share your records">
              <label className="block max-w-md font-medium">Facility
                <select className="input" value={facilityId} onChange={(e) => setFacilityId(e.target.value)} required>
                  <option value="">Choose a facility</option>
                  {facilities.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                </select>
              </label>
              <CategoryPicker legend="What they may see" value={categories} onChange={setCategories} />
              <label className="block max-w-xs font-medium">For how many hours
                <input type="number" min={1} max={720} className="input" value={hours} onChange={(e) => setHours(Number(e.target.value))} required />
              </label>
              <button className={primaryButton} aria-busy={act.busy} disabled={!online || act.busy || categories.length === 0 || !facilityId}>Start sharing</button>
            </form>
          </Panel>
          <section aria-labelledby="sharing-h" className="space-y-3">
            <h2 id="sharing-h" className="text-lg font-semibold">Who can see your records</h2>
            <DataTable caption="Sharing" loading={!lastLoaded && !loadError} rows={consents} rowKey={(c) => c.id} columns={consentColumns}
              searchText={(c) => `${c.facility} ${c.categories.join(' ')} ${c.status}`}
              filters={[
                { id: 'status', label: 'Status', options: optionsFrom(consents, (c) => c.status, cap), test: (c, v) => c.status === v },
                { id: 'facility', label: 'Facility', options: optionsFrom(consents, (c) => c.facility), test: (c, v) => c.facility === v },
              ]}
              defaultSort={{ key: 'until', dir: 'desc' }}
              empty="You have not shared your records with anyone." />
          </section>
        </>
      )}

      {view === 'history' && (
        <DataTable caption="Access history" loading={!lastLoaded && !loadError} rows={history} rowKey={(h) => `${h.occurred_at}-${h.facility}-${h.action}-${h.outcome}-${h.resource_type}-${history.indexOf(h)}`} columns={historyColumns}
          searchText={(h) => `${h.facility ?? ''} ${h.action} ${h.resource_type} ${h.outcome}`}
          filters={[
            { id: 'result', label: 'Result', options: [{ value: 'allowed', label: 'Allowed' }, { value: 'denied', label: 'Not allowed' }], test: (h, v) => (v === 'allowed') === (h.outcome === 'success') },
            { id: 'facility', label: 'Facility', options: optionsFrom(history, (h) => h.facility), test: (h, v) => h.facility === v },
          ]}
          defaultSort={{ key: 'when', dir: 'desc' }}
          empty="No one has looked at your records yet." />
      )}
    </div>
  )
}