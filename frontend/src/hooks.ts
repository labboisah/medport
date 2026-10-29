import { useCallback, useEffect, useState } from 'react'
import { api, type Facility } from './api'

export const inputClass = 'input'
export const primaryButton = 'btn-primary'
export const secondaryButton = 'btn-secondary'

export const CATEGORIES = ['encounters', 'conditions', 'medications', 'allergies', 'observations', 'diagnostic_reports', 'documents', 'referrals']

export const label = (value: string) => value.replace(/_/g, ' ')

export function useOnline(): boolean {
  const [online, setOnline] = useState(() => navigator.onLine)
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => {
      window.removeEventListener('online', on)
      window.removeEventListener('offline', off)
    }
  }, [])
  return online
}

export function useFacilities(): Facility[] {
  const [facilities, setFacilities] = useState<Facility[]>([])
  useEffect(() => {
    api<{ data: Facility[] }>('/facilities')
      .then((r) => setFacilities(r.data))
      .catch(() => setFacilities([]))
  }, [])
  return facilities
}

/** Runs an action, surfacing success and failure as announced messages. */
export function useAction() {
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const run = useCallback(async (fn: () => Promise<string | void>) => {
    setError(null)
    setMessage(null)
    setBusy(true)
    try {
      const done = await fn()
      if (done) setMessage(done)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }, [])

  return { error, message, busy, run }
}


const readView = (fallback: string) => window.location.hash.replace(/^#\//, '') || fallback

/** Hash-based view selection, so the browser back button and bookmarks work without a router dependency. */
export function useView(fallback: string): string {
  const [view, setView] = useState(() => readView(fallback))
  useEffect(() => {
    const on = () => setView(readView(fallback))
    window.addEventListener('hashchange', on)
    return () => window.removeEventListener('hashchange', on)
  }, [fallback])
  return view
}
export const optionsFrom = <T,>(rows: T[], get: (row: T) => string | null | undefined, text: (v: string) => string = (v) => v) =>
  [...new Set(rows.map(get).filter((v): v is string => !!v))].sort().map((v) => ({ value: v, label: text(v) }))
