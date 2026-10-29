import { useState, type FormEvent } from 'react'
import { api, setToken } from './api'
import { useView } from './hooks'
import { PATIENT_NAV, PATIENT_SUBTITLES, PROVIDER_NAV, PROVIDER_SUBTITLES } from './nav'
import { PatientWallet } from './PatientWallet'
import { ProviderWorkspace } from './ProviderWorkspace'
import { Shell } from './Shell'

type Session = { accountType: string; name: string }

const ROLE_LABEL: Record<string, string> = { patient: 'Patient', practitioner: 'Health worker' }

function SignedIn({ session, onSignOut }: { session: Session; onSignOut: () => void }) {
  const patient = session.accountType === 'patient'
  const nav = patient ? PATIENT_NAV : PROVIDER_NAV
  const view = useView(nav[0].id)
  const current = nav.find((n) => n.id === view) ?? nav[0]
  const subtitles = patient ? PATIENT_SUBTITLES : PROVIDER_SUBTITLES

  return (
    <Shell nav={nav} view={current.id} title={current.label} subtitle={subtitles[current.id]}
      user={{ name: session.name, role: ROLE_LABEL[session.accountType] ?? session.accountType }} onSignOut={onSignOut}>
      {patient ? <PatientWallet /> : <ProviderWorkspace />}
    </Shell>
  )
}

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
      const me = await api<{ data: { name?: string; email?: string } }>('/auth/me').catch(() => null)
      setSession({ accountType: res.data.account_type, name: me?.data.name ?? me?.data.email ?? email })
      setPassword('')
    } catch (err) {
      setError((err as Error).message)
    }
  }

  const signOut = async () => {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined)
    setToken(null)
    setSession(null)
    window.location.hash = ''
  }

  if (session && ['patient', 'practitioner'].includes(session.accountType)) {
    return <SignedIn session={session} onSignOut={signOut} />
  }

  return (
    <div className="grid min-h-screen md:grid-cols-2">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:bg-white focus:p-2">Skip to main content</a>
      <div className="hidden flex-col justify-center gap-4 bg-teal-900 p-12 text-teal-50 md:flex">
        <span aria-hidden="true" className="grid h-14 w-14 place-items-center rounded-xl bg-white text-2xl font-bold text-teal-800">M</span>
        <p className="text-4xl font-bold text-white">MediPass</p>
        <p className="max-w-sm text-lg">Your health records, shared only with the people you choose, for as long as you choose.</p>
      </div>
      <main id="main" tabIndex={-1} className="flex flex-col justify-center p-6 sm:p-12">
        <p className="mb-6 rounded-lg bg-amber-100 p-2 text-center text-sm text-amber-900">Pre-release build. Synthetic test data only.</p>
        <form onSubmit={signIn} className="card mx-auto w-full max-w-md space-y-4 p-6" aria-label="Sign in">
          <h1 className="text-2xl font-bold">Sign in</h1>
          {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">{error}</p>}
          {session && <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900">This account type has no screens in this release.</p>}
          <label className="block font-medium">Email<input type="email" autoComplete="username" className="input" value={email} onChange={(e) => setEmail(e.target.value)} required /></label>
          <label className="block font-medium">Password<input type="password" autoComplete="current-password" className="input" value={password} onChange={(e) => setPassword(e.target.value)} required /></label>
          <button className="btn-primary w-full">Sign in</button>
        </form>
      </main>
    </div>
  )
}