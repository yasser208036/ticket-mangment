const DIVISIONS: { amount: number; unit: Intl.RelativeTimeFormatUnit }[] = [
  { amount: 60, unit: 'second' },
  { amount: 60, unit: 'minute' },
  { amount: 24, unit: 'hour' },
  { amount: 7, unit: 'day' },
  { amount: 4.34524, unit: 'week' },
  { amount: 12, unit: 'month' },
  { amount: Number.POSITIVE_INFINITY, unit: 'year' },
]
export function relativeAge(iso: string, now: Date = new Date()): string {
  const parsed = new Date(iso)
  if (Number.isNaN(parsed.getTime())) return ''
  const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
  let delta = (parsed.getTime() - now.getTime()) / 1000
  for (const division of DIVISIONS) {
    if (Math.abs(delta) < division.amount)
      return formatter.format(Math.round(delta), division.unit)
    delta /= division.amount
  }
  return ''
}
