import type { NavItem } from './Shell'

export const PATIENT_NAV: NavItem[] = [
  { id: 'overview', label: 'Overview', icon: 'home' },
  { id: 'records', label: 'My records', icon: 'records' },
  { id: 'sharing', label: 'Sharing', icon: 'share' },
  { id: 'history', label: 'Access history', icon: 'history' },
  { id: 'notifications', label: 'Notifications', icon: 'bell' },
]

export const PROVIDER_NAV: NavItem[] = [
  { id: 'patients', label: 'Find a patient', icon: 'search' },
  { id: 'requests', label: 'My access requests', icon: 'requests' },
  { id: 'referrals', label: 'Referrals', icon: 'referral' },
  { id: 'notifications', label: 'Notifications', icon: 'bell' },
]

export const ADMIN_NAV: NavItem[] = [
  { id: 'overview', label: 'Operations', icon: 'pulse' },
  { id: 'integrations', label: 'Integrations', icon: 'plug' },
  { id: 'verification', label: 'Verification', icon: 'shield' },
  { id: 'incidents', label: 'Incidents', icon: 'alert' },
  { id: 'policy', label: 'Policy settings', icon: 'gear' },
  { id: 'audit', label: 'Audit review', icon: 'history' },
]

export const PATIENT_SUBTITLES: Record<string, string> = {
  overview: 'What needs your attention and a summary of your records',
  records: 'Everything shared into your record, with where it came from',
  sharing: 'Choose who can see your records, and stop sharing at any time',
  history: 'Every time a facility looked at, or tried to look at, your records',
  notifications: 'Short alerts about your sharing and security. They never include health details.',
}

export const PROVIDER_SUBTITLES: Record<string, string> = {
  patients: 'Search by identifier. You only see what the patient has chosen to share.',
  requests: 'Requests you have sent and where they stand',
  referrals: 'Referrals other facilities have sent to yours',
  notifications: 'Short alerts about access requests and account changes',
}

export const ADMIN_SUBTITLES: Record<string, string> = {
  overview: 'Queue, sync, security and storage health at a glance',
  integrations: 'Partner connections, keys, delivery history and reconciliation',
  verification: 'Facilities and health workers waiting to be verified',
  incidents: 'Track and resolve operational and security incidents',
  policy: 'Working-default limits. Every change is audited.',
  audit: 'Who did what, when and with what result. No clinical content.',
}