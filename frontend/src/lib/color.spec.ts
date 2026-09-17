import { describe, expect, it } from 'vitest'
import {
  parseHex,
  relativeLuminance,
  readableTextColor,
  getDarkenedTextColor,
  getBadgeTheme,
} from './color'

describe('color utilities', () => {
  describe('parseHex', () => {
    it('parses valid 6-digit hex strings', () => {
      expect(parseHex('#3B82F6')).toEqual({ r: 59, g: 130, b: 246 })
      expect(parseHex('3b82f6')).toEqual({ r: 59, g: 130, b: 246 })
      expect(parseHex('#ffffff')).toEqual({ r: 255, g: 255, b: 255 })
      expect(parseHex('#000000')).toEqual({ r: 0, g: 0, b: 0 })
    })

    it('parses valid 3-digit hex strings', () => {
      expect(parseHex('#fff')).toEqual({ r: 255, g: 255, b: 255 })
      expect(parseHex('123')).toEqual({ r: 17, g: 34, b: 51 })
    })

    it('returns null for invalid inputs', () => {
      expect(parseHex('')).toBeNull()
      expect(parseHex('invalid')).toBeNull()
      expect(parseHex('#12345')).toBeNull()
      expect(parseHex('#1234567')).toBeNull()
    })
  })

  describe('relativeLuminance', () => {
    it('calculates 0 for pure black and 1 for pure white', () => {
      expect(relativeLuminance('#000000')).toBe(0)
      expect(relativeLuminance('#ffffff')).toBeCloseTo(1, 4)
    })

    it('returns 0 for invalid hex', () => {
      expect(relativeLuminance('bad-hex')).toBe(0)
    })
  })

  describe('readableTextColor', () => {
    it('returns light text for dark backgrounds', () => {
      expect(readableTextColor('#000000')).toBe('#ffffff')
      expect(readableTextColor('#1e293b')).toBe('#ffffff')
      expect(readableTextColor('#111111')).toBe('#ffffff')
    })

    it('returns dark text for light backgrounds', () => {
      expect(readableTextColor('#ffffff')).toBe('#15171b')
      expect(readableTextColor('#F59E0B')).toBe('#15171b')
      expect(readableTextColor('#FEF08A')).toBe('#15171b')
    })

    it('returns dark text fallback for invalid hex', () => {
      expect(readableTextColor('invalid')).toBe('#15171b')
    })
  })

  describe('getDarkenedTextColor', () => {
    it('returns darkened readable tone for bright colors', () => {
      const darkBlue = getDarkenedTextColor('#3B82F6')
      expect(darkBlue).toMatch(/^rgb\(\d+,\s*\d+,\s*\d+\)$/)

      const darkAmber = getDarkenedTextColor('#F59E0B')
      expect(darkAmber).toMatch(/^rgb\(\d+,\s*\d+,\s*\d+\)$/)
    })

    it('returns normalized original hex if already sufficiently dark', () => {
      expect(getDarkenedTextColor('#111111')).toBe('#111111')
    })

    it('handles fallback gracefully for invalid hex', () => {
      expect(getDarkenedTextColor('invalid')).toBe('#334155')
    })
  })

  describe('getBadgeTheme', () => {
    it('generates a harmonious badge theme with translucent bg and vibrant dot', () => {
      const theme = getBadgeTheme('#3B82F6')
      expect(theme.bg).toContain('rgba(59, 130, 246, 0.1')
      expect(theme.border).toContain('rgba(59, 130, 246, 0.22')
      expect(theme.dot).toBe('#3B82F6')
      expect(theme.text).toMatch(/^rgb\(\d+,\s*\d+,\s*\d+\)$/)
      expect(theme.glow).toContain('rgba(59, 130, 246, 0.28')
    })

    it('returns graceful default theme for invalid hex', () => {
      const theme = getBadgeTheme('not-a-color')
      expect(theme.bg).toBe('rgba(100, 116, 139, 0.10)')
      expect(theme.border).toBe('rgba(100, 116, 139, 0.22)')
      expect(theme.dot).toBe('#64748b')
    })
  })
})
