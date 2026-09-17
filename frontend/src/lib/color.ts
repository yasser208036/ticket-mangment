const DARK = '#15171b'
const LIGHT = '#ffffff'

export function relativeLuminance(hex: string): number {
  const channels = [1, 3, 5].map((start) => {
    const value = parseInt(hex.slice(start, start + 2), 16) / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

export function readableTextColor(hex: string): string {
  return !isHex(hex) ? DARK : relativeLuminance(hex) > 0.179 ? DARK : LIGHT
}

function isHex(hex: string): boolean {
  return /^#[0-9A-Fa-f]{6}$/.test(hex)
}

function channels(hex: string): [number, number, number] {
  return [1, 3, 5].map((start) =>
    parseInt(hex.slice(start, start + 2), 16),
  ) as [number, number, number]
}

function toHex([r, g, b]: [number, number, number]): string {
  return `#${[r, g, b].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`
}

/** Blends `hex` towards near-black; `amount` of 1 is fully black. */
function darken(hex: string, amount: number): string {
  const ink = channels(DARK)
  return toHex(
    channels(hex).map((c, i) => c + (ink[i] - c) * amount) as [
      number,
      number,
      number,
    ],
  )
}

/**
 * The darkest the badge's own hue can stay while its text clears 4.5:1 against
 * the pale tint behind it. A pastel category colour would be unreadable at
 * full saturation, and forcing every label to grey would throw away the one
 * signal the colour carries.
 */
export function readableInk(hex: string): string {
  if (!isHex(hex)) return DARK
  for (let amount = 0; amount < 1; amount += 0.1) {
    const candidate = darken(hex, amount)
    if (relativeLuminance(candidate) <= 0.16) return candidate
  }
  return DARK
}

function rgba(hex: string, alpha: number): string {
  return isHex(hex)
    ? `rgba(${channels(hex).join(', ')}, ${alpha})`
    : `rgba(21, 23, 27, ${alpha})`
}

/**
 * A badge as a wash of its colour with a hairline edge, rather than a solid
 * saturated fill. A ticket row carries three of these at once (category,
 * priority, status) and three full-strength pills fought each other and the
 * text around them.
 */
export function tintedBadge(hex: string): Record<string, string> {
  return {
    backgroundColor: rgba(hex, 0.12),
    borderColor: rgba(readableInk(hex), 0.2),
    color: readableInk(hex),
  }
}
