const DARK = '#15171b'
const LIGHT = '#ffffff'

export interface RgbColor {
  r: number
  g: number
  b: number
}

export interface ModernBadgeTheme {
  bg: string
  border: string
  text: string
  dot: string
  glow: string
}

export function parseHex(hex: string): RgbColor | null {
  if (!hex || typeof hex !== 'string') return null
  const clean = hex.trim().replace(/^#/, '')
  if (clean.length === 6 && /^[0-9a-fA-F]{6}$/.test(clean)) {
    return {
      r: parseInt(clean.slice(0, 2), 16),
      g: parseInt(clean.slice(2, 4), 16),
      b: parseInt(clean.slice(4, 6), 16),
    }
  }
  if (clean.length === 3 && /^[0-9a-fA-F]{3}$/.test(clean)) {
    return {
      r: parseInt(clean[0] + clean[0], 16),
      g: parseInt(clean[1] + clean[1], 16),
      b: parseInt(clean[2] + clean[2], 16),
    }
  }
  return null
}

export function relativeLuminance(hex: string): number {
  const rgb = parseHex(hex)
  if (!rgb) return 0
  const channels = [rgb.r, rgb.g, rgb.b].map((c) => {
    const value = c / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

export function readableTextColor(hex: string): string {
  if (!parseHex(hex)) return DARK
  return relativeLuminance(hex) > 0.179 ? DARK : LIGHT
}

/**
 * Returns a rich, darkened version of the input color suitable for light-background
 * text with excellent readability and contrast (WCAG AA compliant).
 */
export function getDarkenedTextColor(hex: string): string {
  const rgb = parseHex(hex)
  if (!rgb) return '#334155'

  const lum = relativeLuminance(hex)
  // If already sufficiently dark, return normalized hex
  if (lum <= 0.08) {
    return hex
  }

  // Calculate scaling factor to reach target luminance ~0.08 for high contrast on light backgrounds
  const targetLum = 0.08
  const factor = Math.min(0.85, Math.sqrt(targetLum / lum))
  const r = Math.max(15, Math.round(rgb.r * factor))
  const g = Math.max(23, Math.round(rgb.g * factor))
  const b = Math.max(42, Math.round(rgb.b * factor))

  return `rgb(${r}, ${g}, ${b})`
}

/**
 * Returns a comprehensive modern badge theme with translucent background,
 * subtle border, accessible text tone, and vibrant dot indicator.
 */
export function getBadgeTheme(hex: string): ModernBadgeTheme {
  const rgb = parseHex(hex)
  if (!rgb) {
    return {
      bg: 'rgba(100, 116, 139, 0.10)',
      border: 'rgba(100, 116, 139, 0.22)',
      text: '#334155',
      dot: '#64748b',
      glow: 'rgba(100, 116, 139, 0.25)',
    }
  }

  const { r, g, b } = rgb
  const text = getDarkenedTextColor(hex)

  return {
    bg: `rgba(${r}, ${g}, ${b}, 0.10)`,
    border: `rgba(${r}, ${g}, ${b}, 0.22)`,
    text,
    dot: hex,
    glow: `rgba(${r}, ${g}, ${b}, 0.28)`,
  }
}
