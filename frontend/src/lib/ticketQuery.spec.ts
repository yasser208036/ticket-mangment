import { describe, expect, it } from 'vitest'
import { fromQuery, toQuery } from './ticketQuery'

describe('ticket query', () => {
  it('omits defaults', () => {
    expect(toQuery(fromQuery({}))).toEqual({})
  })
  it('serializes filters', () => {
    expect(
      toQuery({
        ...fromQuery({}),
        statusIds: [1, 2],
        assignedTo: 'me',
        escalated: false,
      }),
    ).toEqual({ status: '1,2', assignee: 'me', escalated: 'false' })
  })
  it('keeps valid ids from mixed input', () => {
    expect(fromQuery({ status: 'abc,4' }).statusIds).toEqual([4])
  })
  it('rejects unknown sort', () => {
    expect(fromQuery({ sort: 'colour' }).sort).toBe('created_at')
  })
  it('rejects invalid page', () => {
    expect(fromQuery({ page: '-3' }).page).toBe(1)
  })
  it('parses false escalation', () => {
    expect(fromQuery({ escalated: 'false' }).escalated).toBe(false)
  })
  it('round-trips search', () => {
    expect(toQuery(fromQuery({ q: 'printer' }))).toEqual({ q: 'printer' })
    expect(fromQuery({ q: 'printer' }).sort).toBe('relevance')
  })
  it('drops relevance without search', () => {
    expect(fromQuery({ sort: 'relevance' }).sort).toBe('created_at')
  })
  it('keeps relevance with search', () => {
    expect(fromQuery({ sort: 'relevance', q: 'printer' }).sort).toBe(
      'relevance',
    )
  })
  it('round-trips the escalated_at sort', () => {
    expect(toQuery(fromQuery({ sort: 'escalated_at' }))).toEqual({
      sort: 'escalated_at',
    })
    expect(fromQuery({ sort: 'escalated_at' }).sort).toBe('escalated_at')
  })
})
