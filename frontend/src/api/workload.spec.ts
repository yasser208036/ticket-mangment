import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { describe, expect, it } from 'vitest'
import { getWorkload } from './workload'
import type { Workload } from './workload'
import client from './client'

describe('getWorkload', () => {
  it('calls GET /admin/workload and unwraps data.data', async () => {
    let captured: InternalAxiosRequestConfig | undefined
    const payload: Workload = {
      average_open: 4.1,
      band: 2,
      priorities: [],
      open_status_ids: [1, 2, 3, 4, 5],
      agents: [],
    }
    const adapter: AxiosAdapter = async (config) => {
      captured = config
      return {
        data: { data: payload },
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      }
    }
    client.defaults.adapter = adapter

    const result = await getWorkload()

    expect(captured?.method).toBe('get')
    expect(captured?.url).toBe('/admin/workload')
    expect(result).toEqual(payload)
  })
})
