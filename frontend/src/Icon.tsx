const PATHS: Record<string, string> = {
  home: 'M3 11l9-8 9 8M5 10v10h14V10',
  records: 'M7 3h7l5 5v13H7zM14 3v5h5M9 13h6M9 17h6',
  share: 'M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M12 16V4M8 8l4-4 4 4',
  history: 'M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  search: 'M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z',
  referral: 'M4 12h14M13 6l6 6-6 6',
  menu: 'M4 6h16M4 12h16M4 18h16',
  close: 'M6 6l12 12M18 6L6 18',
  collapse: 'M11 7l-5 5 5 5M18 7l-5 5 5 5',
  expand: 'M13 7l5 5-5 5M6 7l5 5-5 5',
  requests: 'M3 13l3-8h12l3 8v6H3zM3 13h5l1 3h6l1-3h5',
}

export function Icon({ name }: { name: keyof typeof PATHS | string }) {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-5 w-5 shrink-0" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d={PATHS[name]} />
    </svg>
  )
}