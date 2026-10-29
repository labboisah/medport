import type { ReactNode } from 'react'
import { CATEGORIES, label } from './hooks'

export function OfflineBanner({ online, lastLoaded }: { online: boolean; lastLoaded: Date | null }) {
  if (online) return null
  return (
    <p role="status" className="rounded border border-amber-400 bg-amber-50 p-3 text-amber-900">
      You are offline.{' '}
      {lastLoaded ? `Showing what was last loaded at ${lastLoaded.toLocaleTimeString()}. It may be out of date.` : 'Nothing has been loaded yet.'}{' '}
      Changes cannot be saved until you are back online.
    </p>
  )
}

export function Messages({ error, message }: { error: string | null; message: string | null }) {
  return (
    <>
      {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-900">{error}</p>}
      {message && <p role="status" className="rounded bg-emerald-50 p-3 text-emerald-900">{message}</p>}
    </>
  )
}

export function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="space-y-3">
      <h2 id={id} className="text-xl font-semibold">{title}</h2>
      {children}
    </section>
  )
}

export function CategoryPicker({ value, onChange, legend }: { value: string[]; onChange: (next: string[]) => void; legend: string }) {
  return (
    <fieldset>
      <legend className="font-medium">{legend}</legend>
      {CATEGORIES.map((c) => (
        <label key={c} className="mr-4 inline-block">
          <input type="checkbox" checked={value.includes(c)} onChange={(e) => onChange(e.target.checked ? [...value, c] : value.filter((x) => x !== c))} /> {label(c)}
        </label>
      ))}
    </fieldset>
  )
}
