import { AxiosError } from 'axios'
import type {
  AxiosAdapter,
  AxiosResponse,
  InternalAxiosRequestConfig,
} from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import client, { setUnauthorizedHandler } from './client'
import { getToken, setToken } from './token'

const successfulAdapter: AxiosAdapter = async (config) => ({
  data: {},
  status: 200,
  statusText: 'OK',
  headers: {},
  config,
})

function failingAdapter(status: number): AxiosAdapter {
  return async (config) => {
    const response = { status, config } as AxiosResponse
    throw new AxiosError(
      'Request failed',
      undefined,
      config,
      undefined,
      response,
    )
  }
}

describe('API client', () => {
  beforeEach(() => {
    localStorage.clear()
    client.defaults.adapter = successfulAdapter
    setUnauthorizedHandler(() => undefined)
  })

  it('attaches a bearer token when one is stored', async () => {
    setToken('abc123')
    let requestConfig: InternalAxiosRequestConfig | undefined
    client.defaults.adapter = async (config) => {
      requestConfig = config
      return successfulAdapter(config)
    }
    await client.get('/health')
    expect(requestConfig?.headers.Authorization).toBe('Bearer abc123')
  })

  it('sends no Authorization header without a token', async () => {
    let requestConfig: InternalAxiosRequestConfig | undefined
    client.defaults.adapter = async (config) => {
      requestConfig = config
      return successfulAdapter(config)
    }
    await client.get('/health')
    expect(requestConfig?.headers.Authorization).toBeUndefined()
  })

  it('clears token and calls handler on 401', async () => {
    const unauthorizedHandler = vi.fn()
    setToken('abc123')
    setUnauthorizedHandler(unauthorizedHandler)
    client.defaults.adapter = failingAdapter(401)
    await expect(client.get('/user')).rejects.toThrow('Request failed')
    expect(getToken()).toBeNull()
    expect(unauthorizedHandler).toHaveBeenCalledOnce()
  })

  it('does not call handler for login 401', async () => {
    const unauthorizedHandler = vi.fn()
    setToken('abc123')
    setUnauthorizedHandler(unauthorizedHandler)
    client.defaults.adapter = failingAdapter(401)
    await expect(client.get('/auth/login')).rejects.toThrow('Request failed')
    expect(getToken()).toBe('abc123')
    expect(unauthorizedHandler).not.toHaveBeenCalled()
  })

  it.each(['/auth/me', '/auth/logout'])(
    'does not call handler for %s 401',
    async (path) => {
      const unauthorizedHandler = vi.fn()
      setToken('abc123')
      setUnauthorizedHandler(unauthorizedHandler)
      client.defaults.adapter = failingAdapter(401)
      await expect(client.get(path)).rejects.toThrow('Request failed')
      expect(getToken()).toBe('abc123')
      expect(unauthorizedHandler).not.toHaveBeenCalled()
    },
  )

  it('keeps token on non-401 errors', async () => {
    const unauthorizedHandler = vi.fn()
    setToken('abc123')
    setUnauthorizedHandler(unauthorizedHandler)
    client.defaults.adapter = failingAdapter(500)
    await expect(client.get('/health')).rejects.toThrow('Request failed')
    expect(getToken()).toBe('abc123')
    expect(unauthorizedHandler).not.toHaveBeenCalled()
  })
})
