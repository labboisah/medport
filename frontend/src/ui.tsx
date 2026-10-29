import type { ReactNode } from 'react'
import { CATEGORIES, label } from './hooks'

export function OfflineBanner({ online, lastLoaded }: { online: boolean; lastLoaded: Date | null }) {
  if (online) return null
  return (
    <p role="status" className="rounded-lg border border-amber-400 bg-amber-50 p-3 text-amber-900">
      You are offline.{' '}
      {lastLoaded ? `Showing what was last loaded at ${lastLoaded.toLocaleTimeString()}. It may be out of date.` : 'Nothing has been loaded yet.'}{' '}
      Changes cannot be saved until you are back online.
    </p>
  )
}

export function Messages({ error, message }: { error: string | null; message: string | null }) {
  return (
    <>
      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">{error}</p>}
      {message && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-emerald-900">{message}</p>}
    </>
  )
}

export function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="space-y-3">
      <h2 id={id} className="text-lg font-semibold text-slate-900">{title}</h2>
      {children}
    </section>
  )
}

export function CategoryPicker({ value, onChange, legend }: { value: string[]; onChange: (next: string[]) => void; legend: string }) {
  return (
    <fieldset className="space-y-1">
      <legend className="font-medium">{legend}</legend>
      {CATEGORIES.map((c) => (
        <label key={c} className="mr-4 inline-flex items-center gap-2">
          <input type="checkbox" checked={value.includes(c)} onChange={(e) => onChange(e.target.checked ? [...value, c] : value.filter((x) => x !== c))} /> {label(c)}
        </label>
      ))}
    </fieldset>
  )
}

export function Stat({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <div className="card p-4">
      <p className="text-sm font-medium text-slate-700">{label}</p>
      <p className="mt-1 text-3xl font-bold text-teal-800">{value}</p>
      {hint && <p className="mt-1 text-sm text-slate-700">{hint}</p>}
    </div>
  )
}

const TONES: Record<string, string> = {
  good: 'badge-good', warn: 'badge-warn', bad: 'badge-bad', info: 'badge-info', neutral: 'badge-neutral',
}

export function Badge({ tone = 'neutral', children }: { tone?: keyof typeof TONES; children: ReactNode }) {
  return <span className={TONES[tone]}>{children}</span>
}

export function Panel({ children }: { children: ReactNode }) {
  return <div className="card space-y-3 p-5">{children}</div>
}