import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it } from 'vitest'
import { changePassword } from './auth'
import client from './client'

describe('changePassword', () => {
  beforeEach(() => localStorage.clear())

  it('sends PATCH with server field names', async () => {
    let captured: InternalAxiosRequestConfig | undefined
    const adapter: AxiosAdapter = async (config) => {
      captured = config
      return {
        data: {},
        status: 204,
        statusText: 'No Content',
        headers: {},
        config,
      }
    }
    client.defaults.adapter = adapter
    const payload = {
      current_password: 'old',
      password: 'new-secret',
      password_confirmation: 'new-secret',
    }
    await changePassword(payload)
    expect(captured?.method).toBe('patch')
    expect(captured?.url).toBe('/auth/password')
    expect(JSON.parse(String(captured?.data))).toEqual(payload)
  })
})
