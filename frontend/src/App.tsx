import { useState, type FormEvent } from 'react'
import { api, setToken } from './api'
import { PatientWallet } from './PatientWallet'
import { ProviderWorkspace } from './ProviderWorkspace'

type Session = { accountType: string }

export default function App() {
  const [session, setSession] = useState<Session | null>(null)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const signIn = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const res = await api<{ data: { token: string; account_type: string } }>('/auth/login', { body: { email, password } })
      setToken(res.data.token)
      setSession({ accountType: res.data.account_type })
      setPassword('')
    } catch (err) {
      setError((err as Error).message)
    }
  }

  const signOut = async () => {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined)
    setToken(null)
    setSession(null)
  }

  return (
    <div className="mx-auto max-w-3xl p-4">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:bg-white focus:p-2">Skip to main content</a>
      <header className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">MediPass</h1>
        {session && <button className="rounded border border-slate-500 px-3 py-1" onClick={signOut}>Sign out</button>}
      </header>
      <p className="mb-4 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">Pre-release build. Synthetic test data only.</p>
      <main id="main" tabIndex={-1}>
        {!session && (
          <form onSubmit={signIn} className="space-y-3" aria-label="Sign in">
            <h2 className="text-xl font-semibold">Sign in</h2>
            {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-900">{error}</p>}
            <label className="block">Email<input type="email" autoComplete="username" className="mt-1 block w-full rounded border border-slate-400 p-2" value={email} onChange={(e) => setEmail(e.target.value)} required /></label>
            <label className="block">Password<input type="password" autoComplete="current-password" className="mt-1 block w-full rounded border border-slate-400 p-2" value={password} onChange={(e) => setPassword(e.target.value)} required /></label>
            <button className="rounded bg-slate-900 px-4 py-2 text-white">Sign in</button>
          </form>
        )}
        {session?.accountType === 'patient' && <PatientWallet />}
        {session?.accountType === 'practitioner' && <ProviderWorkspace />}
        {session && !['patient', 'practitioner'].includes(session.accountType) && <p>This account type has no screens in this release.</p>}
      </main>
    </div>
  )
}
