import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from './api'
import { DataTable, type Column } from './DataTable'
import { Badge, Messages } from './ui'
import { inputClass, label, primaryButton, secondaryButton, useAction, useFacilities, useOnline, optionsFrom } from './hooks'

const nowLocal = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)

export function EncounterForm({ patientId }: { patientId: string }) {
  const [encounterClass, setEncounterClass] = useState('outpatient')
  const [date, setDate] = useState(nowLocal())
  const [reason, setReason] = useState('')
  const [duplicate, setDuplicate] = useState(false)
  const { error, message, busy, run } = useAction()

  const save = (confirm: boolean) => run(async () => {
    setDuplicate(false)
    try {
      await api(`/provider/patients/${patientId}/encounters`, {
        body: { encounter_class: encounterClass, clinical_date: new Date(date).toISOString(), reason, ...(confirm ? { confirm_duplicate: true } : {}) },
      })
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        setDuplicate(true)
      }
      throw e
    }
    setReason('')
    return 'Saved. This entry is labelled as entered by staff and not yet verified.'
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void save(false)
  }

  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Record a visit">
      <p className="text-sm text-slate-700">Use this when the visit is not available from your electronic system. It will be shown to the patient as entered by staff and not verified.</p>
      <Messages error={error} message={message} />
      <label className="block">Type of visit
        <select className={inputClass} value={encounterClass} onChange={(e) => setEncounterClass(e.target.value)}>
          {['outpatient', 'inpatient', 'emergency', 'lab', 'pharmacy'].map((c) => <option key={c} value={c}>{label(c)}</option>)}
        </select>
      </label>
      <label className="block">Date and time
        <input type="datetime-local" className={inputClass} value={date} max={nowLocal()} onChange={(e) => setDate(e.target.value)} required />
      </label>
      <label className="block">Reason for the visit
        <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} required maxLength={255} />
      </label>
      <div className="flex flex-wrap gap-3">
        <button className={primaryButton} aria-busy={busy} disabled={busy}>Save visit</button>
        {duplicate && <button type="button" className={secondaryButton} disabled={busy} onClick={() => void save(true)}>Save anyway, it is a different visit</button>}
      </div>
    </form>
  )
}

export function ReferralForm({ patientId }: { patientId: string }) {
  const facilities = useFacilities()
  const [to, setTo] = useState('')
  const [reason, setReason] = useState('')
  const { error, message, busy, run } = useAction()

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void run(async () => {
      await api(`/provider/patients/${patientId}/referrals`, { body: { to_facility_id: to, reason } })
      setReason('')
      return 'Referral sent. The receiving facility can only see it while the patient’s sharing with them stays active.'
    })
  }

  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Refer the patient">
      <p className="text-sm text-slate-700">The patient must have agreed to share referrals with the facility you choose.</p>
      <Messages error={error} message={message} />
      <label className="block">Refer to
        <select className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} required>
          <option value="">Choose a facility</option>
          {facilities.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
        </select>
      </label>
      <label className="block">Reason for the referral
        <textarea className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} required minLength={5} maxLength={255} />
      </label>
      <button className={primaryButton} aria-busy={busy} disabled={busy || !to}>Send referral</button>
    </form>
  )
}

export function UploadForm({ patientId }: { patientId: string }) {
  const [title, setTitle] = useState('')
  const [type, setType] = useState('discharge_summary')
  const [file, setFile] = useState<File | null>(null)
  const { error, message, busy, run } = useAction()

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void run(async () => {
      const form = new FormData()
      form.append('file', file!)
      form.append('title', title)
      form.append('document_type', type)
      const res = await api<{ data: { scan_status: string } }>(`/provider/patients/${patientId}/documents`, { body: form })
      setTitle('')
      setFile(null)
      return `Uploaded. Safety check: ${label(res.data.scan_status)}.`
    })
  }

  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Upload a document">
      <p className="text-sm text-slate-700">PDF, PNG or JPEG, up to 10 MB. The file is checked before anyone can download it.</p>
      <Messages error={error} message={message} />
      <label className="block">Title
        <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={200} />
      </label>
      <label className="block">Document type
        <select className={inputClass} value={type} onChange={(e) => setType(e.target.value)}>
          {['discharge_summary', 'imaging_report', 'scan', 'other'].map((c) => <option key={c} value={c}>{label(c)}</option>)}
        </select>
      </label>
      <label className="block">File
        <input type="file" accept="application/pdf,image/png,image/jpeg" className={inputClass} onChange={(e) => setFile(e.target.files?.[0] ?? null)} aria-required="true" />
      </label>
      <button className={primaryButton} aria-busy={busy} disabled={busy || !file}>Upload</button>
    </form>
  )
}

type Incoming = { id: string; from_facility: string | null; reason: string; status: string; outcome: string | null; created_at: string; patient: { given_name: string; family_name: string } }

const tone = (s: string) => (s === 'completed' || s === 'accepted' ? 'good' : s === 'declined' ? 'bad' : 'warn')

export function IncomingReferrals() {
  const online = useOnline()
  const [rows, setRows] = useState<Incoming[]>([])
  const [loaded, setLoaded] = useState(false)
  const [outcomes, setOutcomes] = useState<Record<string, string>>({})
  const [loadError, setLoadError] = useState<string | null>(null)
  const act = useAction()

  const load = useCallback(async () => {
    try {
      setRows((await api<{ data: Incoming[] }>('/provider/referrals/incoming')).data)
      setLoadError(null)
      setLoaded(true)
    } catch (e) {
      setLoadError((e as Error).message)
      setLoaded(true)
    }
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const decide = (id: string, status: string, outcome?: string) => act.run(async () => {
    await api(`/provider/referrals/${id}/decision`, { body: { status, ...(outcome ? { outcome } : {}) } })
    await load()
    return `Referral ${status}.`
  })

  const columns: Column<Incoming>[] = [
    { key: 'patient', header: 'Patient', cell: (r) => <span className="font-medium">{r.patient.given_name} {r.patient.family_name}</span>, sort: (r) => r.patient.family_name },
    { key: 'from', header: 'From', cell: (r) => r.from_facility ?? 'Unknown facility', sort: (r) => r.from_facility ?? '' },
    { key: 'reason', header: 'Reason', cell: (r) => r.reason },
    { key: 'status', header: 'Status', cell: (r) => <Badge tone={tone(r.status)}>{label(r.status)}</Badge>, sort: (r) => r.status },
    {
      key: 'action', header: 'Action', cell: (r) => r.status === 'requested' ? (
        <span className="flex gap-2">
          <button className="btn-primary btn-sm" disabled={!online || act.busy} onClick={() => decide(r.id, 'accepted')}>Accept</button>
          <button className="btn-secondary btn-sm" disabled={!online || act.busy} onClick={() => decide(r.id, 'declined')}>Decline</button>
        </span>
      ) : null,
    },
  ]

  return (
    <div className="space-y-3">
      {loadError && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">{loadError}</p>}
      <Messages error={act.error} message={act.message} />
      <DataTable caption="Incoming referrals" loading={!loaded} rows={rows} rowKey={(r) => r.id} columns={columns}
        searchText={(r) => `${r.patient.given_name} ${r.patient.family_name} ${r.from_facility ?? ''} ${r.reason}`}
        filters={[
          { id: 'status', label: 'Status', options: optionsFrom(rows, (r) => r.status, label), test: (r, v) => r.status === v },
          { id: 'from', label: 'From', options: optionsFrom(rows, (r) => r.from_facility), test: (r, v) => r.from_facility === v },
        ]}
        empty="No referrals are waiting."
        detail={(r) => (
          <div className="space-y-2">
            <p><strong>Reason:</strong> {r.reason}</p>
            {r.outcome && <p><strong>Outcome:</strong> {r.outcome}</p>}
            {r.status === 'accepted' && (
              <>
                <label className="block max-w-xl font-medium">Outcome to share with the patient
                  <textarea className="input" value={outcomes[r.id] ?? ''} onChange={(e) => setOutcomes({ ...outcomes, [r.id]: e.target.value })} maxLength={2000} />
                </label>
                <button className="btn-primary" disabled={!online || act.busy || !(outcomes[r.id] ?? '').trim()} onClick={() => decide(r.id, 'completed', outcomes[r.id])}>Mark completed</button>
              </>
            )}
          </div>
        )} />
    </div>
  )
}