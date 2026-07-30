import { sessionStore } from '../stores/session.js'

export function formatMoney(value) {
  const settings = sessionStore.state.settings
  try {
    return new Intl.NumberFormat(settings.currency_locale || 'en-PH', {
      style: 'currency',
      currency: settings.currency_code || 'PHP'
    }).format(Number(value) || 0)
  } catch {
    return `${settings.currency_symbol || '₱'}${(Number(value) || 0).toFixed(2)}`
  }
}

export function formatNumber(value, maximumFractionDigits = 2) {
  return new Intl.NumberFormat(sessionStore.state.settings.currency_locale || 'en-PH', {
    maximumFractionDigits
  }).format(Number(value) || 0)
}

export function formatDateTime(value) {
  if (!value) return '—'
  const parsed = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(parsed.getTime())) return value
  return new Intl.DateTimeFormat(sessionStore.state.settings.currency_locale || 'en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: sessionStore.state.settings.timezone || 'Asia/Manila'
  }).format(parsed)
}
