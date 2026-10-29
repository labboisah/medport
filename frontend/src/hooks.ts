import { useCallback, useEffect, useState } from 'react'
import { api, type Facility } from './api'

export const inputClass = 'mt-1 block w-full rounded border border-slate-500 p-2'
export const primaryButton = 'rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-60'
export const secondaryButton = 'rounded border border-slate-700 px-4 py-2 disabled:opacity-60'

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

