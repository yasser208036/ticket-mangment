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

/**
 * A badge as a solid fill of its own colour, text picked for contrast against
 * it. Status, priority and category are each an admin-chosen hex value, and
 * this is the one formula every badge in the app renders through, so the same
 * colour always reads the same way wherever it appears.
 */
export function solidBadge(hex: string): Record<string, string> {
  return { backgroundColor: hex, color: readableTextColor(hex) }
}
