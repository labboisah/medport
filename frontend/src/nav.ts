import type { NavItem } from './Shell'

export const PATIENT_NAV: NavItem[] = [
  { id: 'overview', label: 'Overview', icon: 'home' },
  { id: 'records', label: 'My records', icon: 'records' },
  { id: 'sharing', label: 'Sharing', icon: 'share' },
  { id: 'history', label: 'Access history', icon: 'history' },
]

export const PROVIDER_NAV: NavItem[] = [
  { id: 'patients', label: 'Find a patient', icon: 'search' },
  { id: 'requests', label: 'My access requests', icon: 'requests' },
  { id: 'referrals', label: 'Referrals', icon: 'referral' },
]

export const PATIENT_SUBTITLES: Record<string, string> = {
  overview: 'What needs your attention and a summary of your records',
  records: 'Everything shared into your record, with where it came from',
  sharing: 'Choose who can see your records, and stop sharing at any time',
  history: 'Every time a facility looked at, or tried to look at, your records',
}

export const PROVIDER_SUBTITLES: Record<string, string> = {
  patients: 'Search by identifier. You only see what the patient has chosen to share.',
  requests: 'Requests you have sent and where they stand',
  referrals: 'Referrals other facilities have sent to yours',
}