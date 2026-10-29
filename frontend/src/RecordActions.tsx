import { useState, type FormEvent } from 'react'
import { api, downloadDocument, type TimelineItem } from './api'
import { Messages } from './ui'
import { inputClass, secondaryButton, primaryButton, useAction } from './hooks'

export function DownloadButton({ item }: { item: TimelineItem }) {
  const { error, busy, run } = useAction()
  if (item.category !== 'documents') return null
  const name = String(item.title ?? 'document').replace(/[^\w.-]+/g, '-')
  return (
    <div className="mt-2">
      <button className={secondaryButton} disabled={busy} onClick={() => run(() => downloadDocument(item.id, name))}>
        Download {String(item.title ?? 'document')}
      </button>
      <Messages error={error} message={null} />
    </div>
  )
}

/** Lets a patient dispute a record or ask for a correction; the facility's reply is not built yet. */
export function CorrectionForm({ item, onDone }: { item: TimelineItem; onDone: () => void }) {
  const [open, setOpen] = useState(false)
  const [kind, setKind] = useState('dispute')
  const [reason, setReason] = useState('')
  const { error, message, busy, run } = useAction()

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void run(async () => {
      await api(`/patient/records/${item.category}/${item.id}/corrections`, { body: { kind, reason } })
      setOpen(false)
      setReason('')
      onDone()
      return 'Your request was sent. The record now shows that you disputed it.'
    })
  }

  return (
    <div className="mt-2">
      {!open && <button className={secondaryButton} onClick={() => setOpen(true)}>Report a problem with this record</button>}
      {open && (
        <form onSubmit={submit} className="space-y-2" aria-label={`Report a problem with ${String(item.title ?? item.reason ?? item.type)}`}>
          <label className="block">What is wrong?
            <select className={inputClass} value={kind} onChange={(e) => setKind(e.target.value)}>
              <option value="dispute">I disagree with this record</option>
              <option value="correction">Something needs correcting</option>
              <option value="entered_in_error">This was entered by mistake</option>
            </select>
          </label>
          <label className="block">Explain in your own words
            <textarea className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} required minLength={5} />
          </label>
          <div className="flex gap-3">
            <button className={primaryButton} disabled={busy}>Send</button>
            <button type="button" className={secondaryButton} onClick={() => setOpen(false)}>Cancel</button>
          </div>
        </form>
      )}
      <Messages error={error} message={message} />
    </div>
  )
}
