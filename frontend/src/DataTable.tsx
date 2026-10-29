import { Fragment, useId, useMemo, useState, type ReactNode } from 'react'

export type Column<T> = {
  key: string
  header: string
  cell: (row: T) => ReactNode
  sort?: (row: T) => string | number
  className?: string
}

export type Filter<T> = {
  id: string
  label: string
  options: { value: string; label: string }[]
  test: (row: T, value: string) => boolean
}

type Props<T> = {
  caption: string
  rows: T[]
  rowKey: (row: T) => string
  columns: Column<T>[]
  searchText?: (row: T) => string
  filters?: Filter<T>[]
  pageSizes?: number[]
  empty: string
  detail?: (row: T) => ReactNode
  defaultSort?: { key: string; dir: 'asc' | 'desc' }
}

/** Searchable, filterable, sortable, paginated table. Everything is client-side over the rows the API already returned. */
export function DataTable<T>({ caption, rows, rowKey, columns, searchText, filters = [], pageSizes = [5, 10, 25], empty, detail, defaultSort }: Props<T>) {
  const id = useId()
  const [query, setQuery] = useState('')
  const [chosen, setChosen] = useState<Record<string, string>>({})
  const [sort, setSort] = useState(defaultSort ?? null)
  const [pageSize, setPageSize] = useState(pageSizes[0])
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<string | null>(null)

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()
    let out = rows.filter((r) => (!q || !searchText || searchText(r).toLowerCase().includes(q))
      && filters.every((f) => !chosen[f.id] || f.test(r, chosen[f.id])))
    const col = columns.find((c) => c.key === sort?.key)
    if (col?.sort && sort) {
      const dir = sort.dir === 'asc' ? 1 : -1
      out = [...out].sort((a, b) => {
        const x = col.sort!(a)
        const y = col.sort!(b)
        return (x < y ? -1 : x > y ? 1 : 0) * dir
      })
    }
    return out
  }, [rows, query, chosen, sort, columns, filters, searchText])

  const pages = Math.max(1, Math.ceil(visible.length / pageSize))
  const current = Math.min(page, pages)
  const start = (current - 1) * pageSize
  const shown = visible.slice(start, start + pageSize)
  const active = Object.values(chosen).filter(Boolean).length + (query ? 1 : 0)

  const reset = () => setPage(1)
  const toggleSort = (key: string) => {
    setSort(sort?.key === key ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' })
    reset()
  }

  return (
    <div className="card">
      <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
        {searchText && (
          <label className="min-w-48 flex-1 text-sm font-medium">Search
            <input type="search" className="input" placeholder={`Search ${caption.toLowerCase()}`} value={query} onChange={(e) => { setQuery(e.target.value); reset() }} />
          </label>
        )}
        {filters.map((f) => (
          <label key={f.id} className="text-sm font-medium">{f.label}
            <select className="input" value={chosen[f.id] ?? ''} onChange={(e) => { setChosen({ ...chosen, [f.id]: e.target.value }); reset() }}>
              <option value="">All</option>
              {f.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </label>
        ))}
        {active > 0 && <button className="btn-secondary btn-sm" onClick={() => { setQuery(''); setChosen({}); reset() }}>Clear filters</button>}
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <caption className="sr-only">{caption}</caption>
          <thead className="bg-slate-50 text-slate-700">
            <tr>
              {columns.map((c) => (
                <th key={c.key} scope="col" className={`px-4 py-3 font-semibold ${c.className ?? ''}`}
                  aria-sort={sort?.key === c.key ? (sort.dir === 'asc' ? 'ascending' : 'descending') : c.sort ? 'none' : undefined}>
                  {c.sort ? (
                    <button className="inline-flex items-center gap-1 font-semibold hover:text-teal-800" onClick={() => toggleSort(c.key)}>
                      {c.header}<span aria-hidden="true">{sort?.key === c.key ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}</span>
                    </button>
                  ) : c.header}
                </th>
              ))}
              {detail && <th scope="col" className="px-4 py-3"><span className="sr-only">Details</span></th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {shown.length === 0 && (
              <tr><td colSpan={columns.length + (detail ? 1 : 0)} className="px-4 py-8 text-center text-slate-700">{active > 0 ? 'Nothing matches your search or filters.' : empty}</td></tr>
            )}
            {shown.map((row) => {
              const key = rowKey(row)
              const expanded = open === key
              return (
                <Fragment key={key}>
                  <tr className="align-top hover:bg-slate-50">
                    {columns.map((c) => <td key={c.key} className={`px-4 py-3 ${c.className ?? ''}`}>{c.cell(row)}</td>)}
                    {detail && (
                      <td className="px-4 py-3 text-right">
                        <button className="btn-secondary btn-sm" aria-expanded={expanded} aria-controls={`${id}-${key}`} onClick={() => setOpen(expanded ? null : key)}>
                          {expanded ? 'Hide details' : 'Details'}
                        </button>
                      </td>
                    )}
                  </tr>
                  {detail && expanded && (
                    <tr id={`${id}-${key}`} className="bg-slate-50"><td colSpan={columns.length + 1} className="px-4 py-4">{detail(row)}</td></tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-sm">
        <p aria-live="polite">
          {visible.length === 0 ? 'No results' : `Showing ${start + 1}–${start + shown.length} of ${visible.length}`}
          {visible.length !== rows.length && ` (filtered from ${rows.length})`}
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2">Rows per page
            <select className="rounded-lg border border-slate-400 bg-white px-2 py-1" value={pageSize} onChange={(e) => { setPageSize(Number(e.target.value)); reset() }}>
              {pageSizes.map((n) => <option key={n} value={n}>{n}</option>)}
            </select>
          </label>
          <nav aria-label={`${caption} pages`} className="flex items-center gap-2">
            <button className="btn-secondary btn-sm" disabled={current <= 1} onClick={() => setPage(current - 1)}>Previous</button>
            <span>Page {current} of {pages}</span>
            <button className="btn-secondary btn-sm" disabled={current >= pages} onClick={() => setPage(current + 1)}>Next</button>
          </nav>
        </div>
      </div>
    </div>
  )
}