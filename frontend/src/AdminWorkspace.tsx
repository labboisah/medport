import { useState, type FormEvent } from 'react'
import { api } from './api'
import { DataTable, type Column } from './DataTable'
import { label, optionsFrom, useAction, useLoad, useOnline } from './hooks'
import { Badge, Messages, OfflineBanner, PageLoader, Panel, Section, Stat } from './ui'

const POLL = 60_000
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ')
const when = (s: string | null) => (s ? new Date(s).toLocaleString() : '-')

type Metrics = { data: {
  database_ms: number
  queue: { pending: number; lag_seconds: number; failed: number }
  sync: { failed: number; dead_letter: number; partial_24h: number }
  integrations: { active: number; disabled: number; keys_expired: number }
  security_24h: { failed_logins: number; denied_record_reads: number; integration_denials: number }
  storage: { free_percent: number | null }
  verification_backlog: { facilities: number; practitioners: number }
  open_incidents: number
} }
type FailedJob = { id: string; queue: string; job: string; exception: string; failed_at: string }

function Overview() {
  const metrics = useLoad<Metrics>('/admin/ops/metrics', POLL)
  const failed = useLoad<{ data: FailedJob[] }>('/admin/ops/failed-jobs', POLL)
  const online = useOnline()
  const act = useAction()
  const m = metrics.data?.data

  const columns: Column<FailedJob>[] = [
    { key: 'job', header: 'Job', cell: (j) => j.job, sort: (j) => j.job },
    { key: 'exception', header: 'Error type', cell: (j) => j.exception },
    { key: 'failed', header: 'Failed at', cell: (j) => when(j.failed_at), sort: (j) => j.failed_at },
    { key: 'action', header: 'Action', cell: (j) => (
      <button className="btn-secondary btn-sm" disabled={!online || act.busy} onClick={() => act.run(async () => {
        await api(`/admin/ops/failed-jobs/${j.id}/retry`, { method: 'POST' })
        await Promise.all([failed.reload(), metrics.reload()])
        return 'Job requeued.'
      })}>Retry<span className="sr-only"> {j.job}</span></button>
    ) },
  ]

  if (!metrics.loaded) return <PageLoader text="Loading operations" />
  return (
    <div className="space-y-6">
      <OfflineBanner online={online} lastLoaded={metrics.data ? new Date() : null} />
      {metrics.error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">{metrics.error}</p>}
      <Messages error={act.error} message={act.message} />
      {m && (
        <>
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Stat label="Queue waiting" value={m.queue.pending} hint={`Oldest ${m.queue.lag_seconds}s`} />
            <Stat label="Failed queue jobs" value={m.queue.failed} hint={m.queue.failed ? 'Needs attention' : 'None'} />
            <Stat label="Sync dead-lettered" value={m.sync.dead_letter} hint={`${m.sync.failed} failing now`} />
            <Stat label="Open incidents" value={m.open_incidents} />
            <Stat label="Failed sign-ins (24 h)" value={m.security_24h.failed_logins} />
            <Stat label="Denied record reads (24 h)" value={m.security_24h.denied_record_reads} />
            <Stat label="Storage free" value={m.storage.free_percent === null ? '-' : `${m.storage.free_percent}%`} hint={`Database ${m.database_ms} ms`} />
            <Stat label="Awaiting verification" value={m.verification_backlog.facilities + m.verification_backlog.practitioners} hint={`${m.verification_backlog.facilities} facilities, ${m.verification_backlog.practitioners} people`} />
          </div>
          <p className="text-sm text-slate-700">Integrations: {m.integrations.active} active, {m.integrations.disabled} disabled, {m.integrations.keys_expired} with expired keys. Refreshes every minute.</p>
        </>
      )}
      <Section id="failed-h" title="Failed background jobs">
        <p className="text-sm text-slate-700">Only the job type and error type are shown, never message content.</p>
        <DataTable caption="Failed jobs" rows={failed.data?.data ?? []} rowKey={(j) => j.id} columns={columns} empty="No failed jobs." loading={!failed.loaded} />
      </Section>
    </div>
  )
}

type Integration = { id: string; name: string; facility: string | null; protocol: string; environment: string; status: string; scopes: string[]; key_expires_at: string | null; last_success_at: string | null; jobs: Record<string, number> }
type Job = { id: string; status: string; attempts: number; last_attempt_at: string | null; next_retry_at: string | null; last_error_code: string | null; received: number; created: number; updated: number; skipped_stale: number; rejected: number }
type Recon = Record<string, { active: number; entered_in_error: number; latest_source_update: string | null }>

const jobTone = (s: string) => (s === 'succeeded' ? 'good' : s === 'dead_letter' ? 'bad' : s === 'failed' || s === 'partial' ? 'warn' : 'neutral')
const WRITE_CATEGORIES = ['encounters', 'conditions', 'medications', 'allergies', 'observations', 'diagnostic_reports']

function IntegrationDetail({ endpoint, onChanged }: { endpoint: Integration; onChanged: () => void }) {
  const jobs = useLoad<{ data: Job[] }>(`/admin/integrations/${endpoint.id}/jobs`)
  const recon = useLoad<{ data: Recon }>(`/admin/integrations/${endpoint.id}/reconciliation`)
  const act = useAction()
  const online = useOnline()

  return (
    <div className="space-y-4">
      <Messages error={act.error} message={act.message} />
      <div>
        <h3 className="font-semibold">Recent deliveries</h3>
        {!jobs.loaded ? <p>Loading…</p> : (jobs.data?.data.length ?? 0) === 0 ? <p className="text-slate-700">No deliveries yet.</p> : (
          <ul className="divide-y divide-slate-200">
            {jobs.data!.data.map((j) => (
              <li key={j.id} className="flex flex-wrap items-center gap-3 py-2">
                <Badge tone={jobTone(j.status)}>{cap(j.status)}</Badge>
                <span>{j.received} received, {j.created} new, {j.updated} updated, {j.skipped_stale} stale, {j.rejected} rejected</span>
                <span className="text-sm text-slate-700">Attempts {j.attempts}{j.last_error_code ? ` · ${j.last_error_code}` : ''}{j.next_retry_at ? ` · retry after ${when(j.next_retry_at)}` : ''}</span>
                {(j.status === 'failed' || j.status === 'dead_letter') && (
                  <button className="btn-secondary btn-sm" disabled={!online || act.busy} onClick={() => act.run(async () => {
                    await api(`/admin/sync-jobs/${j.id}/replay`, { method: 'POST' })
                    await Promise.all([jobs.reload()])
                    onChanged()
                    return 'Delivery re-opened. Ask the partner to send it again with the same key.'
                  })}>Re-open delivery</button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      <div>
        <h3 className="font-semibold">Reconciliation</h3>
        <p className="text-sm text-slate-700">What MediPass holds from this source, to compare with the partner's own totals.</p>
        {recon.data && (
          <table className="mt-2 w-full text-left text-sm">
            <caption className="sr-only">Records held from {endpoint.name}</caption>
            <thead><tr><th scope="col" className="py-1">Category</th><th scope="col">Active</th><th scope="col">Entered in error</th><th scope="col">Latest source update</th></tr></thead>
            <tbody>{Object.entries(recon.data.data).map(([c, v]) => <tr key={c}><th scope="row" className="py-1 font-medium">{cap(c)}</th><td>{v.active}</td><td>{v.entered_in_error}</td><td>{when(v.latest_source_update)}</td></tr>)}</tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function Integrations() {
  const list = useLoad<{ data: Integration[] }>('/admin/integrations', POLL)
  const online = useOnline()
  const act = useAction()
  const [facility, setFacility] = useState('')
  const [name, setName] = useState('')
  const [protocol, setProtocol] = useState('fhir_rest')
  const [scopes, setScopes] = useState<string[]>([])
  const [issued, setIssued] = useState<{ name: string; key: string } | null>(null)
  const facilities = useLoad<{ data: { id: string; name: string }[] }>('/facilities')

  const create = (e: FormEvent) => {
    e.preventDefault()
    void act.run(async () => {
      const res = await api<{ data: Integration & { api_key: string } }>('/admin/integrations', { body: { facility_id: facility, name, protocol, scopes } })
      setIssued({ name: res.data.name, key: res.data.api_key })
      setName(''); setScopes([])
      await list.reload()
      return 'Integration created. Copy the key now; it will not be shown again.'
    })
  }

  const columns: Column<Integration>[] = [
    { key: 'name', header: 'Name', cell: (i) => <span className="font-medium">{i.name}</span>, sort: (i) => i.name },
    { key: 'facility', header: 'Facility', cell: (i) => i.facility ?? '-', sort: (i) => i.facility ?? '' },
    { key: 'status', header: 'Status', cell: (i) => <Badge tone={i.status === 'active' ? 'good' : 'neutral'}>{cap(i.status)}</Badge>, sort: (i) => i.status },
    { key: 'protocol', header: 'Type', cell: (i) => `${i.protocol} (${i.environment})` },
    { key: 'last', header: 'Last success', cell: (i) => when(i.last_success_at), sort: (i) => i.last_success_at ?? '' },
    { key: 'health', header: 'Deliveries', cell: (i) => Object.entries(i.jobs).map(([s, n]) => <Badge key={s} tone={jobTone(s)}>{n} {cap(s)}</Badge>) },
    { key: 'action', header: 'Action', cell: (i) => i.status === 'active'
      ? <button className="btn-danger btn-sm" disabled={!online || act.busy} onClick={() => act.run(async () => { await api(`/admin/integrations/${i.id}/disable`, { method: 'POST' }); await list.reload(); return 'Integration disabled.' })}>Disable<span className="sr-only"> {i.name}</span></button> : null },
  ]

  return (
    <div className="space-y-6">
      <Messages error={act.error} message={act.message} />
      {issued && (
        <div role="status" className="rounded-lg border border-amber-400 bg-amber-50 p-4 text-amber-900">
          <p className="font-semibold">Key for {issued.name} (shown once)</p>
          <p className="mt-1 break-all font-mono text-sm select-all">{issued.key}</p>
          <button className="btn-secondary btn-sm mt-2" onClick={() => setIssued(null)}>I have copied it</button>
        </div>
      )}
      <Section id="int-h" title="Partner connections">
        <DataTable caption="Integrations" rows={list.data?.data ?? []} rowKey={(i) => i.id} columns={columns} loading={!list.loaded} empty="No integrations yet."
          searchText={(i) => `${i.name} ${i.facility ?? ''}`}
          filters={[{ id: 'status', label: 'Status', options: optionsFrom(list.data?.data ?? [], (i) => i.status, cap), test: (i, v) => i.status === v }]}
          detail={(i) => <IntegrationDetail endpoint={i} onChanged={() => void list.reload()} />} />
      </Section>
      <Section id="new-int-h" title="Add a sandbox connection">
        <form onSubmit={create} className="card space-y-3 p-5" aria-label="New integration">
          <p className="text-sm text-slate-700">Sandbox only, synthetic data only. The key expires after 90 days and can only write the categories you tick.</p>
          <label className="block font-medium">Facility
            <select className="input" value={facility} onChange={(e) => setFacility(e.target.value)} required>
              <option value="">Choose a facility</option>
              {(facilities.data?.data ?? []).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </select>
          </label>
          <label className="block font-medium">Name<input className="input" value={name} onChange={(e) => setName(e.target.value)} maxLength={100} required /></label>
          <label className="block font-medium">Type
            <select className="input" value={protocol} onChange={(e) => setProtocol(e.target.value)}>
              <option value="fhir_rest">FHIR Bundle</option>
              <option value="adapter">Synthetic Mediflow adapter</option>
            </select>
          </label>
          <fieldset className="space-y-1">
            <legend className="font-medium">May write</legend>
            {WRITE_CATEGORIES.map((c) => (
              <label key={c} className="mr-4 inline-flex items-center gap-2">
                <input type="checkbox" checked={scopes.includes(c)} onChange={(e) => setScopes(e.target.checked ? [...scopes, c] : scopes.filter((x) => x !== c))} /> {label(c)}
              </label>
            ))}
          </fieldset>
          <button className="btn-primary" disabled={!online || act.busy || scopes.length === 0} aria-busy={act.busy}>Create connection</button>
        </form>
      </Section>
    </div>
  )
}

type Pending = { data: { facilities: { id: string; name: string; type: string; status: string }[]; practitioners: { id: string; name: string; status: string }[] } }

function Verification() {
  const pending = useLoad<Pending>('/admin/pending-verification')
  const online = useOnline()
  const act = useAction()
  const verify = (kind: 'facilities' | 'practitioners', id: string) => act.run(async () => {
    await api(`/admin/${kind}/${id}/verify`, { method: 'POST' })
    await pending.reload()
    return 'Verified.'
  })
  if (!pending.loaded) return <PageLoader text="Loading verification queue" />
  const facilities = pending.data?.data.facilities ?? []
  const people = pending.data?.data.practitioners ?? []
  return (
    <div className="space-y-6">
      <Messages error={act.error ?? pending.error} message={act.message} />
      <p className="text-sm text-slate-700">Only verify after checking the evidence the policy owner requires. Each verification is audited.</p>
      <Section id="fac-h" title="Facilities">
        <DataTable caption="Facilities awaiting verification" rows={facilities} rowKey={(f) => f.id} empty="No facilities are waiting."
          searchText={(f) => f.name} columns={[
            { key: 'name', header: 'Name', cell: (f) => f.name, sort: (f) => f.name },
            { key: 'type', header: 'Type', cell: (f) => cap(f.type) },
            { key: 'status', header: 'Status', cell: (f) => <Badge tone="warn">{cap(f.status)}</Badge> },
            { key: 'a', header: 'Action', cell: (f) => <button className="btn-primary btn-sm" disabled={!online || act.busy} onClick={() => verify('facilities', f.id)}>Verify<span className="sr-only"> {f.name}</span></button> },
          ]} />
      </Section>
      <Section id="ppl-h" title="Health workers">
        <DataTable caption="Health workers awaiting verification" rows={people} rowKey={(p) => p.id} empty="No health workers are waiting."
          searchText={(p) => p.name} columns={[
            { key: 'name', header: 'Name', cell: (p) => p.name, sort: (p) => p.name },
            { key: 'status', header: 'Status', cell: (p) => <Badge tone="warn">{cap(p.status)}</Badge> },
            { key: 'a', header: 'Action', cell: (p) => <button className="btn-primary btn-sm" disabled={!online || act.busy} onClick={() => verify('practitioners', p.id)}>Verify<span className="sr-only"> {p.name}</span></button> },
          ]} />
      </Section>
    </div>
  )
}

type Incident = { id: string; category: string; severity: string; status: string; title: string; summary: string | null; created_at: string }
const SEVERITY_TONE: Record<string, 'bad' | 'warn' | 'info' | 'neutral'> = { critical: 'bad', high: 'bad', medium: 'warn', low: 'info' }
const INCIDENT_CATEGORIES = ['account_compromise', 'suspected_breach', 'partner_outage', 'incorrect_patient_match', 'failed_sync', 'emergency_access_review', 'other']

function Incidents() {
  const list = useLoad<{ data: Incident[] }>('/admin/incidents')
  const online = useOnline()
  const act = useAction()
  const [category, setCategory] = useState(INCIDENT_CATEGORIES[0])
  const [severity, setSeverity] = useState('medium')
  const [title, setTitle] = useState('')
  const [summary, setSummary] = useState('')

  const create = (e: FormEvent) => {
    e.preventDefault()
    void act.run(async () => {
      await api('/admin/incidents', { body: { category, severity, title, summary: summary || null } })
      setTitle(''); setSummary('')
      await list.reload()
      return 'Incident recorded.'
    })
  }
  const setStatus = (i: Incident, status: string) => act.run(async () => {
    await api(`/admin/incidents/${i.id}`, { method: 'PATCH', body: { status } })
    await list.reload()
    return 'Incident updated.'
  })

  const rows = list.data?.data ?? []
  const columns: Column<Incident>[] = [
    { key: 'title', header: 'Incident', cell: (i) => <span className="font-medium">{i.title}</span>, sort: (i) => i.title },
    { key: 'category', header: 'Category', cell: (i) => cap(i.category), sort: (i) => i.category },
    { key: 'severity', header: 'Severity', cell: (i) => <Badge tone={SEVERITY_TONE[i.severity]}>{cap(i.severity)}</Badge>, sort: (i) => ['low', 'medium', 'high', 'critical'].indexOf(i.severity) },
    { key: 'status', header: 'Status', cell: (i) => (
      <label><span className="sr-only">Status of {i.title}</span>
        <select className="input" value={i.status} disabled={!online || act.busy} onChange={(e) => void setStatus(i, e.target.value)}>
          {['open', 'investigating', 'contained', 'resolved'].map((s) => <option key={s} value={s}>{cap(s)}</option>)}
        </select>
      </label>
    ), sort: (i) => i.status },
    { key: 'created', header: 'Opened', cell: (i) => when(i.created_at), sort: (i) => i.created_at },
  ]

  return (
    <div className="space-y-6">
      <Messages error={act.error ?? list.error} message={act.message} />
      <Section id="inc-h" title="Incidents">
        <DataTable caption="Incidents" rows={rows} rowKey={(i) => i.id} columns={columns} loading={!list.loaded} empty="No incidents recorded." searchText={(i) => `${i.title} ${i.summary ?? ''}`}
          defaultSort={{ key: 'created', dir: 'desc' }}
          filters={[
            { id: 'status', label: 'Status', options: optionsFrom(rows, (i) => i.status, cap), test: (i, v) => i.status === v },
            { id: 'severity', label: 'Severity', options: optionsFrom(rows, (i) => i.severity, cap), test: (i, v) => i.severity === v },
          ]}
          detail={(i) => <p>{i.summary || 'No summary.'}</p>} />
      </Section>
      <Section id="new-inc-h" title="Record an incident">
        <form onSubmit={create} className="card space-y-3 p-5" aria-label="New incident">
          <p className="text-sm text-slate-700">Do not put patient names, identifiers or tokens in incident text.</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block font-medium">Category
              <select className="input" value={category} onChange={(e) => setCategory(e.target.value)}>{INCIDENT_CATEGORIES.map((c) => <option key={c} value={c}>{cap(c)}</option>)}</select>
            </label>
            <label className="block font-medium">Severity
              <select className="input" value={severity} onChange={(e) => setSeverity(e.target.value)}>{['low', 'medium', 'high', 'critical'].map((s) => <option key={s} value={s}>{cap(s)}</option>)}</select>
            </label>
          </div>
          <label className="block font-medium">Title<input className="input" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={150} required /></label>
          <label className="block font-medium">Summary<textarea className="input" rows={3} value={summary} onChange={(e) => setSummary(e.target.value)} maxLength={4000} /></label>
          <button className="btn-primary" disabled={!online || act.busy} aria-busy={act.busy}>Record incident</button>
        </form>
      </Section>
    </div>
  )
}

type PolicyRow = { key: string; value: number | null; min: number; max: number; enforced: boolean; working_default: boolean }

function PolicyEditor({ row, onSave, disabled }: { row: PolicyRow; onSave: (key: string, value: number) => void; disabled: boolean }) {
  const [value, setValue] = useState(String(row.value ?? ''))
  return (
    <form className="flex items-center gap-2" onSubmit={(e) => { e.preventDefault(); onSave(row.key, Number(value)) }}>
      <label><span className="sr-only">{label(row.key)}</span>
        <input type="number" className="input w-28" min={row.min} max={row.max} value={value} onChange={(e) => setValue(e.target.value)} required />
      </label>
      <button className="btn-secondary btn-sm" disabled={disabled || value === String(row.value ?? '')}>Save<span className="sr-only"> {label(row.key)}</span></button>
    </form>
  )
}

function Policy() {
  const list = useLoad<{ data: PolicyRow[] }>('/admin/policy')
  const online = useOnline()
  const act = useAction()
  const save = (key: string, value: number) => act.run(async () => {
    await api(`/admin/policy/${key}`, { method: 'PUT', body: { value } })
    await list.reload()
    return `${cap(key)} updated.`
  })
  if (!list.loaded) return <PageLoader text="Loading settings" />
  return (
    <div className="space-y-4">
      <Messages error={act.error ?? list.error} message={act.message} />
      <Panel>
        <p className="text-sm text-slate-700">These are working defaults until the policy owners approve final values. Retention values are recorded only; nothing is deleted automatically.</p>
        <DataTable caption="Policy settings" rows={list.data?.data ?? []} rowKey={(r) => r.key} empty="No settings." searchText={(r) => r.key}
          columns={[
            { key: 'key', header: 'Setting', cell: (r) => <span className="capitalize">{label(r.key)}</span>, sort: (r) => r.key },
            { key: 'value', header: 'Value', cell: (r) => <PolicyEditor key={`${r.key}-${r.value}`} row={r} onSave={save} disabled={!online || act.busy} /> },
            { key: 'range', header: 'Allowed', cell: (r) => `${r.min} to ${r.max}` },
            { key: 'state', header: 'State', cell: (r) => <>{r.working_default ? <Badge tone="warn">Working default</Badge> : <Badge tone="good">Set by admin</Badge>} {!r.enforced && <Badge>Recorded only</Badge>}</> },
          ]} />
      </Panel>
    </div>
  )
}

type AuditRow = { id: string; at: string; actor_type: string; resource_type: string; action: string; outcome: string; purpose: string | null; authorization_basis: string | null; correlation_id: string | null }

function AuditReview() {
  const list = useLoad<{ data: AuditRow[] }>('/admin/audit')
  const rows = list.data?.data ?? []
  return (
    <div className="space-y-4">
      <Messages error={list.error} message={null} />
      <p className="text-sm text-slate-700">The latest 100 events. Entries describe who did what and the result; they never contain record content.</p>
      <DataTable caption="Audit events" rows={rows} rowKey={(r) => r.id} loading={!list.loaded} empty="No audit events." defaultSort={{ key: 'at', dir: 'desc' }}
        searchText={(r) => `${r.resource_type} ${r.action} ${r.correlation_id ?? ''}`}
        filters={[
          { id: 'outcome', label: 'Result', options: optionsFrom(rows, (r) => r.outcome, cap), test: (r, v) => r.outcome === v },
          { id: 'type', label: 'Type', options: optionsFrom(rows, (r) => r.resource_type, cap), test: (r, v) => r.resource_type === v },
          { id: 'actor', label: 'Actor', options: optionsFrom(rows, (r) => r.actor_type, cap), test: (r, v) => r.actor_type === v },
        ]}
        columns={[
          { key: 'at', header: 'When', cell: (r) => <time dateTime={r.at}>{when(r.at)}</time>, sort: (r) => r.at },
          { key: 'actor', header: 'Actor', cell: (r) => cap(r.actor_type), sort: (r) => r.actor_type },
          { key: 'what', header: 'Activity', cell: (r) => `${cap(r.action)} (${label(r.resource_type)})`, sort: (r) => r.resource_type },
          { key: 'outcome', header: 'Result', cell: (r) => <Badge tone={r.outcome === 'success' ? 'good' : r.outcome === 'denied' ? 'warn' : 'bad'}>{cap(r.outcome)}</Badge>, sort: (r) => r.outcome },
        ]}
        detail={(r) => <p className="text-sm">Basis: {r.authorization_basis ?? '-'} · Purpose: {r.purpose ?? '-'} · Reference: <span className="font-mono">{r.correlation_id ?? '-'}</span></p>} />
    </div>
  )
}

export function AdminWorkspace({ view }: { view: string }) {
  switch (view) {
    case 'integrations': return <Integrations />
    case 'verification': return <Verification />
    case 'incidents': return <Incidents />
    case 'policy': return <Policy />
    case 'audit': return <AuditReview />
    default: return <Overview />
  }
}