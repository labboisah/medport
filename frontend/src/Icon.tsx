const PATHS: Record<string, string> = {
  home: 'M3 11l9-8 9 8M5 10v10h14V10',
  records: 'M7 3h7l5 5v13H7zM14 3v5h5M9 13h6M9 17h6',
  share: 'M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M12 16V4M8 8l4-4 4 4',
  history: 'M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  search: 'M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z',
  referral: 'M4 12h14M13 6l6 6-6 6',
  bell: 'M6 9a6 6 0 1112 0c0 6 2 7 2 7H4s2-1 2-7M10 20a2 2 0 004 0',
  gear: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12l2-1-2-4-2 1-2-1-1-2h-4l-1 2-2 1-2-1-2 4 2 1v2l-2 1 2 4 2-1 2 1 1 2h4l1-2 2-1 2 1 2-4-2-1z',
  shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
  alert: 'M12 4l9 16H3zM12 10v4M12 17v.01',
  plug: 'M9 3v5M15 3v5M6 8h12v3a6 6 0 01-12 0zM12 17v4',
  pulse: 'M3 12h4l3-7 4 14 3-7h4',
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