import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { DataTable } from './DataTable'
import { optionsFrom } from './hooks'

type Row = { id: string; name: string; kind: string; n: number }
const rows: Row[] = Array.from({ length: 12 }, (_, i) => ({ id: String(i), name: `Item ${String(i + 1).padStart(2, '0')}`, kind: i % 3 === 0 ? 'alpha' : 'beta', n: i }))

function table() {
  return render(
    <DataTable caption="Items" rows={rows} rowKey={(r) => r.id} searchText={(r) => `${r.name} ${r.kind}`} empty="Nothing here."
      columns={[{ key: 'name', header: 'Name', cell: (r) => r.name, sort: (r) => r.n }]}
      filters={[{ id: 'kind', label: 'Kind', options: optionsFrom(rows, (r) => r.kind), test: (r, v) => r.kind === v }]}
      detail={(r) => <p>More about {r.name}</p>} />,
  )
}

const bodyRows = () => within(screen.getAllByRole('rowgroup')[1]).getAllByRole('row')

describe('DataTable', () => {
  it('paginates and reports the range', async () => {
    table()
    expect(bodyRows()).toHaveLength(5)
    expect(screen.getByText('Showing 1–5 of 12')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Rows per page'), '25')
    expect(bodyRows()).toHaveLength(12)
    expect(screen.getByText('Page 1 of 1')).toBeInTheDocument()
  })

  it('searches, filters, clears and explains an empty result', async () => {
    table()
    await userEvent.type(screen.getByLabelText('Search'), 'item 07')
    expect(bodyRows()).toHaveLength(1)
    expect(screen.getByText(/filtered from 12/)).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Search'))
    await userEvent.selectOptions(screen.getByLabelText('Kind'), 'alpha')
    expect(screen.getByText('Showing 1–4 of 4 (filtered from 12)')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Search'), 'zzz')
    expect(screen.getByText('Nothing matches your search or filters.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }))
    expect(screen.getByText('Showing 1–5 of 12')).toBeInTheDocument()
  })

  it('sorts with aria-sort and expands details accessibly', async () => {
    table()
    const header = screen.getByRole('columnheader', { name: /Name/ })
    await userEvent.click(within(header).getByRole('button'))
    expect(header).toHaveAttribute('aria-sort', 'ascending')
    await userEvent.click(within(header).getByRole('button'))
    expect(header).toHaveAttribute('aria-sort', 'descending')
    expect(within(bodyRows()[0]).getByText('Item 12')).toBeInTheDocument()

    const details = screen.getAllByRole('button', { name: 'Details' })[0]
    await userEvent.click(details)
    expect(details).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('More about Item 12')).toBeInTheDocument()
  })
})