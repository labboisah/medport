import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Icon } from './Icon'

export type NavItem = { id: string; label: string; icon: string }

type Props = {
  nav: NavItem[]
  badges?: Record<string, number>
  view: string
  title: string
  subtitle?: string
  user: { name: string; role: string }
  onSignOut: () => void
  children: ReactNode
}

const STORE = 'medipass.sidebar-collapsed'

// Only a harmless layout preference is stored; never any account or health data.
const readCollapsed = () => {
  try { return localStorage.getItem(STORE) === '1' } catch { return false }
}

export function Shell({ nav, badges = {}, view, title, subtitle, user, onSignOut, children }: Props) {
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [open, setOpen] = useState(false)
  const menuButton = useRef<HTMLButtonElement>(null)

  const toggleCollapsed = () => {
    const next = !collapsed
    setCollapsed(next)
    try { localStorage.setItem(STORE, next ? '1' : '0') } catch { /* preference only */ }
  }

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { setOpen(false); menuButton.current?.focus() }
    }
    const onHash = () => setOpen(false)
    document.addEventListener('keydown', onKey)
    window.addEventListener('hashchange', onHash)
    return () => {
      document.removeEventListener('keydown', onKey)
      window.removeEventListener('hashchange', onHash)
    }
  }, [open])

  const slim = collapsed
  return (
    <div className="min-h-screen md:flex">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-white focus:p-2">Skip to main content</a>

      <div className="sticky top-0 z-30 flex items-center justify-between gap-3 bg-teal-900 px-4 py-3 text-white md:hidden">
        <button ref={menuButton} className="btn-sm inline-flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-teal-800" aria-expanded={open} aria-controls="sidebar" onClick={() => setOpen(!open)}>
          <Icon name={open ? 'close' : 'menu'} /><span className="sr-only">{open ? 'Close menu' : 'Open menu'}</span>
        </button>
        <p className="text-lg font-bold">MediPass</p>
        <button className="rounded-lg border border-teal-300 px-3 py-1 text-sm hover:bg-teal-800" onClick={onSignOut}>Sign out</button>
      </div>

      {open && <div aria-hidden="true" className="fixed inset-0 z-30 bg-slate-900/50 md:hidden" onClick={() => setOpen(false)} />}

      <aside id="sidebar" className={`fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-teal-900 text-teal-50 transition-transform md:sticky md:top-0 md:h-screen md:shrink-0 md:translate-x-0 md:transition-[width] ${open ? 'translate-x-0' : 'invisible -translate-x-full md:visible'} ${slim ? 'md:w-[4.5rem]' : 'md:w-64'}`}>
        <div className={`flex items-center gap-3 px-5 py-4 ${slim ? 'md:justify-center md:px-0' : ''}`}>
          <span aria-hidden="true" className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white text-lg font-bold text-teal-800">M</span>
          <p className={`text-xl font-bold text-white ${slim ? 'md:sr-only' : ''}`}>MediPass</p>
        </div>
        <nav aria-label="Main" className="flex-1 overflow-y-auto px-3">
          <ul className="flex flex-col gap-1">
            {nav.map((item) => {
              const current = item.id === view
              return (
                <li key={item.id}>
                  <a href={`#/${item.id}`} aria-current={current ? 'page' : undefined} title={slim ? item.label : undefined}
                    className={`flex items-center gap-3 whitespace-nowrap rounded-lg px-3 py-2.5 font-medium ${slim ? 'md:justify-center' : ''} ${current ? 'bg-teal-700 text-white' : 'hover:bg-teal-800'}`}>
                    <Icon name={item.icon} /><span className={slim ? 'md:sr-only' : ''}>{item.label}</span>
                    {badges[item.id] > 0 && <span className={`ml-auto rounded-full bg-amber-300 px-2 text-xs font-bold text-slate-900 ${slim ? 'md:hidden' : ''}`}>{badges[item.id]}<span className="sr-only"> unread</span></span>}
                  </a>
                </li>
              )
            })}
          </ul>
        </nav>
        <div className="hidden border-t border-teal-800 p-3 md:block">
          <button className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 hover:bg-teal-800 ${slim ? 'justify-center' : ''}`} aria-expanded={!slim} onClick={toggleCollapsed} title={slim ? 'Expand sidebar' : undefined}>
            <Icon name={slim ? 'expand' : 'collapse'} />
            <span className={slim ? 'sr-only' : ''}>{slim ? 'Expand sidebar' : 'Collapse sidebar'}</span>
          </button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <p className="bg-amber-100 px-4 py-1.5 text-center text-sm text-amber-900">Pre-release build. Synthetic test data only.</p>
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
          <div className="min-w-0">
            <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">{title}</h1>
            {subtitle && <p className="text-sm text-slate-700">{subtitle}</p>}
          </div>
          <div className="hidden items-center gap-3 md:flex">
            <p className="text-right text-sm leading-tight"><span className="block font-medium">{user.name}</span><span className="text-slate-700">{user.role}</span></p>
            <button className="btn-secondary btn-sm" onClick={onSignOut}>Sign out</button>
          </div>
          <p className="text-sm leading-tight md:hidden"><span className="font-medium">{user.name}</span> · <span className="text-slate-700">{user.role}</span></p>
        </header>
        <main id="main" tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6">{children}</main>
      </div>
    </div>
  )
}