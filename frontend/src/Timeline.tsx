import type { Timeline, TimelineItem } from './api'

const originLabel: Record<TimelineItem['origin'], string> = {
  source_system: 'From source system',
  staff_entered: 'Entered manually by staff',
  patient_entered: 'Entered by the patient',
}

const verificationLabel: Record<string, string> = {
  unverified: 'Not verified',
  source_verified: 'Verified by source',
  clinician_verified: 'Verified by a clinician',
  patient_entered: 'Patient-entered, not verified',
}

const format = (value: string) => new Date(value).toLocaleDateString()

export function TimelineView({ timeline }: { timeline: Timeline }) {
  return (
    <section aria-label="Record timeline">
      <p role="note" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        This timeline may be incomplete. {timeline.meta.note}
      </p>
      {timeline.data.length === 0 ? (
        <p className="mt-4 text-slate-700">No records are available to you.</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {timeline.data.map((item) => (
            <li key={item.id} className="rounded border border-slate-300 bg-white p-4">
              <h3 className="font-semibold">
                {item.local_display ?? String(item.reason ?? item.type).replace(/_/g, ' ')}
                <span className="ml-2 text-sm font-normal text-slate-600">({item.category.replace(/_/g, ' ')})</span>
              </h3>
              <dl className="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div><dt className="inline font-medium">Source: </dt><dd className="inline">{item.source.facility ?? 'Unknown'}</dd></div>
                <div><dt className="inline font-medium">Origin: </dt><dd className="inline">{originLabel[item.origin]}</dd></div>
                <div><dt className="inline font-medium">Clinical date: </dt><dd className="inline">{format(item.clinical_date)}</dd></div>
                <div><dt className="inline font-medium">Received: </dt><dd className="inline">{format(item.ingested_at)}</dd></div>
                <div><dt className="inline font-medium">Verification: </dt><dd className="inline">{verificationLabel[item.verification_status] ?? item.verification_status}</dd></div>
              </dl>
              {item.stale && <p className="mt-2 text-sm font-medium text-amber-800">Older than {timeline.meta.stale_after_days} days. It may be out of date.</p>}
              {item.correction_open && <p className="mt-2 text-sm font-medium text-red-800">The patient has disputed this record. A review is open.</p>}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
