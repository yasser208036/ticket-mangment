import type { AxiosAdapter } from 'axios'
import { AxiosError } from 'axios'
import { describe, expect, it } from 'vitest'
import { listAgents } from './agents'
import client from './client'

describe('listAgents', () => {
  it('unwraps data.data', async () => {
    const adapter: AxiosAdapter = async (config) => ({
      data: { data: [{ id: 1, name: 'Alan Turing' }] },
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    })
    client.defaults.adapter = adapter
    await expect(listAgents()).resolves.toEqual([
      { id: 1, name: 'Alan Turing' },
    ])
  })

  it('propagates a rejection', async () => {
    const adapter: AxiosAdapter = async (config) => {
      throw new AxiosError('Request failed', undefined, config)
    }
    client.defaults.adapter = adapter
    await expect(listAgents()).rejects.toThrow()
  })
})
