import type { ReactNode } from 'react'
import type { Timeline, TimelineItem } from './api'
import { DataTable, type Column, type Filter } from './DataTable'
import { label, optionsFrom } from './hooks'
import { Badge } from './ui'

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
const title = (i: TimelineItem) => String(i.local_display ?? i.title ?? i.reason ?? i.type).replace(/_/g, ' ')
const verified = (s: string) => s === 'source_verified' || s === 'clinician_verified'

export function RecordsTable({ timeline, actions }: { timeline: Timeline; actions?: (item: TimelineItem) => ReactNode }) {
  const rows = timeline.data

  const columns: Column<TimelineItem>[] = [
    { key: 'date', header: 'Clinical date', cell: (i) => format(i.clinical_date), sort: (i) => i.clinical_date },
    { key: 'record', header: 'Record', cell: (i) => <span className="font-medium capitalize">{title(i)}</span>, sort: (i) => title(i).toLowerCase() },
    { key: 'category', header: 'Category', cell: (i) => <span className="capitalize">{label(i.category)}</span>, sort: (i) => i.category },
    { key: 'source', header: 'Source', cell: (i) => i.source.facility ?? 'Unknown', sort: (i) => i.source.facility ?? '' },
    {
      key: 'status', header: 'Status', cell: (i) => (
        <span className="flex flex-wrap gap-1">
          <Badge tone={verified(i.verification_status) ? 'good' : 'warn'}>{verificationLabel[i.verification_status] ?? i.verification_status}</Badge>
          {i.stale && <Badge tone="warn">May be out of date</Badge>}
          {i.correction_open && <Badge tone="bad">Disputed</Badge>}
        </span>
      ),
    },
  ]

  const filters: Filter<TimelineItem>[] = [
    { id: 'category', label: 'Category', options: optionsFrom(rows, (i) => i.category, label), test: (i, v) => i.category === v },
    { id: 'source', label: 'Source', options: optionsFrom(rows, (i) => i.source.facility), test: (i, v) => i.source.facility === v },
    { id: 'origin', label: 'Entered by', options: Object.entries(originLabel).map(([value, text]) => ({ value, label: text })), test: (i, v) => i.origin === v },
    { id: 'flag', label: 'Flags', options: [{ value: 'stale', label: 'May be out of date' }, { value: 'disputed', label: 'Disputed' }, { value: 'unverified', label: 'Not verified' }], test: (i, v) => v === 'stale' ? i.stale : v === 'disputed' ? i.correction_open : !verified(i.verification_status) },
  ]

  return (
    <section aria-label="Record timeline" className="space-y-3">
      <p role="note" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        This timeline may be incomplete. {timeline.meta.note}
      </p>
      <DataTable
        caption="Records"
        rows={rows}
        rowKey={(i) => i.id}
        columns={columns}
        filters={filters}
        searchText={(i) => `${title(i)} ${i.category} ${i.source.facility ?? ''} ${originLabel[i.origin]}`}
        defaultSort={{ key: 'date', dir: 'desc' }}
        empty="No records are available to you."
        detail={(i) => (
          <div className="space-y-2">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
              <div><dt className="inline font-medium">Source: </dt><dd className="inline">{i.source.facility ?? 'Unknown'}</dd></div>
              <div><dt className="inline font-medium">Origin: </dt><dd className="inline">{originLabel[i.origin]}</dd></div>
              <div><dt className="inline font-medium">Clinical date: </dt><dd className="inline">{format(i.clinical_date)}</dd></div>
              <div><dt className="inline font-medium">Received: </dt><dd className="inline">{format(i.ingested_at)}</dd></div>
              <div><dt className="inline font-medium">Verification: </dt><dd className="inline">{verificationLabel[i.verification_status] ?? i.verification_status}</dd></div>
              {i.outcome_text ? <div className="sm:col-span-2"><dt className="inline font-medium">Outcome: </dt><dd className="inline">{String(i.outcome_text)}</dd></div> : null}
            </dl>
            {i.stale && <p className="text-sm font-medium text-amber-900">Older than {timeline.meta.stale_after_days} days. It may be out of date.</p>}
            {i.correction_open && <p className="text-sm font-medium text-red-900">The patient has disputed this record. A review is open.</p>}
            {actions?.(i)}
          </div>
        )}
      />
    </section>
  )
}