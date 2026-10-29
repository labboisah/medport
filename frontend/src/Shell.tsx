import type { ReactNode } from 'react'
import { Icon } from './Icon'

export type NavItem = { id: string; label: string; icon: string }

type Props = {
  nav: NavItem[]
  view: string
  title: string
  subtitle?: string
  user: { name: string; role: string }
  onSignOut: () => void
  children: ReactNode
}

export function Shell({ nav, view, title, subtitle, user, onSignOut, children }: Props) {
  return (
    <div className="min-h-screen md:flex">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-white focus:p-2">Skip to main content</a>
      <aside className="bg-teal-900 text-teal-50 md:sticky md:top-0 md:h-screen md:w-64 md:shrink-0">
        <div className="flex items-center gap-3 px-5 py-4">
          <span aria-hidden="true" className="grid h-9 w-9 place-items-center rounded-lg bg-white text-lg font-bold text-teal-800">M</span>
          <p className="text-xl font-bold text-white">MediPass</p>
        </div>
        <nav aria-label="Main" className="px-3 pb-3 md:pb-0">
          <ul className="flex gap-1 overflow-x-auto md:flex-col">
            {nav.map((item) => {
              const current = item.id === view
              return (
                <li key={item.id}>
                  <a href={`#/${item.id}`} aria-current={current ? 'page' : undefined}
                    className={`flex items-center gap-3 whitespace-nowrap rounded-lg px-3 py-2 font-medium ${current ? 'bg-teal-700 text-white' : 'hover:bg-teal-800'}`}>
                    <Icon name={item.icon} />{item.label}
                  </a>
                </li>
              )
            })}
          </ul>
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <p className="bg-amber-100 px-4 py-1.5 text-center text-sm text-amber-900">Pre-release build. Synthetic test data only.</p>
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-4">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">{title}</h1>
            {subtitle && <p className="text-sm text-slate-700">{subtitle}</p>}
          </div>
          <div className="flex items-center gap-3">
            <p className="text-right text-sm leading-tight"><span className="block font-medium">{user.name}</span><span className="text-slate-700">{user.role}</span></p>
            <button className="btn-secondary btn-sm" onClick={onSignOut}>Sign out</button>
          </div>
        </header>
        <main id="main" tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 space-y-6 p-6">{children}</main>
      </div>
    </div>
  )
}