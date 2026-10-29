export type TimelineItem = {
  id: string
  type: string
  category: string
  source: { facility: string | null; system: string | null; author: string | null }
  origin: 'source_system' | 'staff_entered' | 'patient_entered'
  clinical_date: string
  ingested_at: string
  verification_status: string
  stale: boolean
  correction_open: boolean
  local_display?: string
  [key: string]: unknown
}

export type Timeline = {
  data: TimelineItem[]
  meta: { complete: boolean; note: string; sources: string[]; stale_after_days: number }
}

export class ApiError extends Error {
  status: number
  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

// The token lives in memory only, so nothing sensitive is left in browser storage on a shared device.
let token: string | null = null

export const setToken = (value: string | null) => {
  token = value
}

export async function api<T = unknown>(path: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
  const response = await fetch(`/api/v1${path}`, {
    method: init.method ?? (init.body ? 'POST' : 'GET'),
    headers: {
      Accept: 'application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: init.body ? JSON.stringify(init.body) : undefined,
  }).catch(() => {
    throw new ApiError(0, 'Cannot reach the server. Check your connection and try again.')
  })

  const json = await response.json().catch(() => null)
  if (!response.ok) {
    throw new ApiError(response.status, friendlyMessage(response.status, json?.error?.message))
  }
  return json as T
}

function friendlyMessage(status: number, serverMessage?: string): string {
  if (status === 401) return 'Your sign-in is invalid or has expired. Please sign in again.'
  if (status === 403) return 'You do not have access to this. The patient may not have shared it with you.'
  if (status === 429) return 'Too many attempts. Please wait a moment and try again.'
  if (status >= 500) return 'Something went wrong on our side. Please try again.'
  return serverMessage ?? 'The request could not be completed.'
}
