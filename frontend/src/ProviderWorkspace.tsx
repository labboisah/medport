import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, type Timeline } from './api'
import { DataTable, type Column } from './DataTable'
import { EncounterForm, IncomingReferrals, ReferralForm, UploadForm } from './FacilityTools'
import { label, useAction, useOnline, useView, optionsFrom } from './hooks'
import { DownloadButton } from './RecordActions'
import { RecordsTable } from './Timeline'
import { Badge, CategoryPicker, Messages, OfflineBanner, Panel } from './ui'

type Match = { id: string; given_name: string; family_name: string; birth_year: string | null; identity_verification: string }
type MyRequest = { id: string; facility: string; categories: string[]; reason: string; requested_hours: number; status: string; expires_at: string }

const tone = (s: string) => (s === 'approved' || s === 'limited' ? 'good' : s === 'denied' ? 'bad' : s === 'pending' ? 'warn' : 'neutral')

function FindPatient() {
  const [system, setSystem] = useState('')
  const [value, setValue] = useState('')
  const [match, setMatch] = useState<Match | null>(null)
  const [searched, setSearched] = useState(false)
  const [timeline, setTimeline] = useState<Timeline | null>(null)
  const [categories, setCategories] = useState<string[]>(['allergies'])
  const [reason, setReason] = useState('')
  const act = useAction()

  const search = (e: FormEvent) => {
    e.preventDefault()
    void act.run(async () => {
      const res = await api<{ data: Match[] }>('/patients/search', { body: { system, value } })
      setMatch(res.data[0] ?? null)
      setSearched(true)
      setTimeline(null)
    })
  }

  const open = () => act.run(async () => {
    setTimeline(await api<Timeline>(`/provider/patients/${match!.id}/timeline`))
  })

  const request = (e: FormEvent) => {
    e.preventDefault()
    void act.run(async () => {
      await api('/provider/access-requests', { body: { patient_id: match!.id, categories, reason, duration_hours: 24 } })
      return 'Request sent. You will only see records the patient chooses to share.'
    })
  }

  return (
    <div className="space-y-6">
      <p className="rounded-lg border border-slate-200 bg-white p-3 text-sm">Purpose of access: <strong>direct care</strong>. Every view is recorded in the patient’s access history.</p>
      <Messages error={act.error} message={act.message} />

      <Panel>
        <form onSubmit={search} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" aria-label="Find a patient">
          <label className="block font-medium">Identifier type<input className="input" value={system} onChange={(e) => setSystem(e.target.value)} placeholder="e.g. synthetic_national_id" required /></label>
          <label className="block font-medium">Identifier value<input className="input" value={value} onChange={(e) => setValue(e.target.value)} placeholder="e.g. SYN-NID-0001" required /></label>
          <button className="btn-primary">Search</button>
        </form>
      </Panel>

      {searched && !match && <p>No patient matches that identifier. Check it and try again.</p>}

      {match && (
        <section aria-label="Matched patient" className="space-y-6">
          <div className="card flex flex-wrap items-center justify-between gap-3 p-5">
            <div className="flex items-center gap-4">
              <span aria-hidden="true" className="grid h-12 w-12 place-items-center rounded-full bg-teal-100 text-lg font-bold text-teal-900">{match.given_name[0]}{match.family_name[0]}</span>
              <div>
                <p className="text-lg font-semibold">{match.given_name} {match.family_name}</p>
                <p className="text-sm text-slate-700">Born {match.birth_year ?? 'unknown'} · <Badge tone={match.identity_verification === 'verified' ? 'good' : 'warn'}>Identity {match.identity_verification === 'verified' ? 'verified' : 'not verified'}</Badge></p>
              </div>
            </div>
            <button className="btn-primary" onClick={open}>Open shared records</button>
          </div>

          {timeline && <RecordsTable timeline={timeline} actions={(item) => <DownloadButton item={item} />} />}

          <div className="grid gap-6 lg:grid-cols-2">
            <Panel>
              <h2 className="text-lg font-semibold">Ask the patient for access</h2>
              <form onSubmit={request} className="space-y-3" aria-label="Request access">
                <CategoryPicker legend="Records you need" value={categories} onChange={setCategories} />
                <label className="block font-medium">Reason<input className="input" value={reason} onChange={(e) => setReason(e.target.value)} required minLength={5} /></label>
                <button className="btn-secondary" disabled={categories.length === 0}>Send request</button>
              </form>
            </Panel>
            <Panel><h2 className="text-lg font-semibold">Record a visit manually</h2><EncounterForm patientId={match.id} /></Panel>
            <Panel><h2 className="text-lg font-semibold">Refer this patient</h2><ReferralForm patientId={match.id} /></Panel>
            <Panel><h2 className="text-lg font-semibold">Upload a document</h2><UploadForm patientId={match.id} /></Panel>
          </div>
        </section>
      )}
    </div>
  )
}

function MyRequests() {
  const [rows, setRows] = useState<MyRequest[]>([])
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setRows((await api<{ data: MyRequest[] }>('/provider/access-requests')).data)
      setError(null)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const columns: Column<MyRequest>[] = [
    { key: 'categories', header: 'Records requested', cell: (r) => r.categories.map(label).join(', ') },
    { key: 'reason', header: 'Reason', cell: (r) => r.reason },
    { key: 'hours', header: 'Hours', cell: (r) => r.requested_hours, sort: (r) => r.requested_hours },
    { key: 'status', header: 'Status', cell: (r) => <Badge tone={tone(r.status)}>{label(r.status)}</Badge>, sort: (r) => r.status },
    { key: 'expires', header: 'Expires', cell: (r) => new Date(r.expires_at).toLocaleString(), sort: (r) => r.expires_at },
  ]

  return (
    <div className="space-y-3">
      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">{error}</p>}
      <DataTable caption="Access requests" rows={rows} rowKey={(r) => r.id} columns={columns}
        searchText={(r) => `${r.categories.join(' ')} ${r.reason} ${r.status}`}
        filters={[{ id: 'status', label: 'Status', options: optionsFrom(rows, (r) => r.status, label), test: (r, v) => r.status === v }]}
        defaultSort={{ key: 'expires', dir: 'desc' }}
        empty="You have not asked any patient for access yet." />
    </div>
  )
}

export function ProviderWorkspace() {
  const view = useView('patients')
  const online = useOnline()

  return (
    <div className="space-y-6">
      <OfflineBanner online={online} lastLoaded={null} />
      {view === 'patients' && <FindPatient />}
      {view === 'requests' && <MyRequests />}
      {view === 'referrals' && <IncomingReferrals />}
    </div>
  )
}