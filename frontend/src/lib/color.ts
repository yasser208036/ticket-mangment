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
  return !/^#[0-9A-Fa-f]{6}$/.test(hex)
    ? DARK
    : relativeLuminance(hex) > 0.179
      ? DARK
      : LIGHT
}
