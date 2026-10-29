import { api } from './api'
import { DataTable, type Column } from './DataTable'
import { label, useAction, useLoad, useOnline, type NotificationItem, type NotificationList } from './hooks'
import { Badge, Messages, OfflineBanner, PageLoader, Panel, Section } from './ui'

type Preference = { event: string; in_app: boolean; can_disable: boolean }
type Preferences = { data: Preference[]; meta: { note: string } }

export function NotificationsView({ list, reload, loaded }: { list: NotificationList | null; reload: () => Promise<void>; loaded: boolean }) {
  const prefs = useLoad<Preferences>('/notifications/preferences')
  const online = useOnline()
  const act = useAction()

  const columns: Column<NotificationItem>[] = [
    { key: 'when', header: 'When', cell: (n) => <time dateTime={n.created_at}>{new Date(n.created_at).toLocaleString()}</time>, sort: (n) => n.created_at },
    { key: 'message', header: 'Message', cell: (n) => n.message },
    { key: 'status', header: 'Status', cell: (n) => (n.read ? <Badge>Read</Badge> : <Badge tone="info">New</Badge>), sort: (n) => (n.read ? 1 : 0) },
  ]

  const toggle = (p: Preference) => act.run(async () => {
    await api('/notifications/preferences', { method: 'PUT', body: { event: p.event, in_app: !p.in_app } })
    await prefs.reload()
    return `${label(p.event)} notices turned ${p.in_app ? 'off' : 'on'}.`
  })

  const markRead = () => act.run(async () => {
    await api('/notifications/read', { method: 'POST' })
    await reload()
    return 'All notifications marked as read.'
  })

  if (!loaded) return <PageLoader text="Loading notifications" />

  return (
    <div className="space-y-6">
      <OfflineBanner online={online} lastLoaded={loaded ? new Date() : null} />
      <Messages error={act.error} message={act.message} />
      <Section id="inbox-h" title="Your notifications">
        <div className="flex items-center justify-between gap-3">
          <p className="text-slate-700">{list?.meta.unread ?? 0} unread</p>
          <button className="btn-secondary btn-sm" disabled={!online || act.busy || !list?.meta.unread} aria-busy={act.busy} onClick={markRead}>Mark all as read</button>
        </div>
        <DataTable caption="Notifications" rows={list?.data ?? []} rowKey={(n) => n.id} columns={columns} empty="You have no notifications." defaultSort={{ key: 'when', dir: 'desc' }}
          searchText={(n) => n.message}
          filters={[{ id: 'state', label: 'Status', options: [{ value: 'new', label: 'New' }, { value: 'read', label: 'Read' }], test: (n, v) => (v === 'new' ? !n.read : n.read) }]} />
      </Section>
      <Section id="prefs-h" title="What you are notified about">
        <Panel>
          <ul className="divide-y divide-slate-200">
            {(prefs.data?.data ?? []).map((p) => (
              <li key={p.event} className="flex items-center justify-between gap-3 py-2">
                <span className="capitalize">{label(p.event)}{!p.can_disable && <span className="ml-2 text-sm text-slate-700">(always on)</span>}</span>
                <button role="switch" aria-checked={p.in_app} disabled={!p.can_disable || !online || act.busy} onClick={() => toggle(p)} className="btn-secondary btn-sm">
                  {p.in_app ? 'On' : 'Off'}<span className="sr-only"> for {label(p.event)}</span>
                </button>
              </li>
            ))}
          </ul>
          {prefs.data && <p className="text-sm text-slate-700">{prefs.data.meta.note}</p>}
        </Panel>
      </Section>
    </div>
  )
}