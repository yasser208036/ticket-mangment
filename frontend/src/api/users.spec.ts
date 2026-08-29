import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { AxiosError } from 'axios'
import { describe, expect, it } from 'vitest'
import { deleteBlockedBy, deleteUser, resetUserPassword } from './users'
import type { AdminUser } from './users'
import client from './client'

function capture(status = 204): {
  seen: () => InternalAxiosRequestConfig | undefined
} {
  let captured: InternalAxiosRequestConfig | undefined
  const adapter: AxiosAdapter = async (config) => {
    captured = config
    return { data: null, status, statusText: 'OK', headers: {}, config }
  }
  client.defaults.adapter = adapter
  return { seen: () => captured }
}

function unprocessable(data: unknown): AxiosError {
  const error = new AxiosError('Unprocessable')
  error.response = {
    data,
    status: 422,
    statusText: 'Unprocessable',
    headers: {},
    config: {} as InternalAxiosRequestConfig,
  }
  return error
}

const heir: AdminUser = {
  id: 7,
  name: 'Heir',
  email: 'heir@example.test',
  role: 'agent',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

describe('deleteUser', () => {
  it('sends DELETE with no body when no destination is given', async () => {
    const request = capture()
    await deleteUser(3)
    expect(request.seen()?.method).toBe('delete')
    expect(request.seen()?.url).toBe('/admin/users/3')
    expect(request.seen()?.data).toBeUndefined()
  })

  it('sends the destination in the body when one is given', async () => {
    const request = capture()
    await deleteUser(3, 7)
    expect(JSON.parse(String(request.seen()?.data))).toEqual({ reassign_to: 7 })
  })
})

describe('resetUserPassword', () => {
  it('patches the nested password route with both fields', async () => {
    const request = capture()
    await resetUserPassword(3, {
      password: 'brand-new-secret',
      current_password: 'admin-secret',
    })
    expect(request.seen()?.method).toBe('patch')
    expect(request.seen()?.url).toBe('/admin/users/3/password')
    expect(JSON.parse(String(request.seen()?.data))).toEqual({
      password: 'brand-new-secret',
      current_password: 'admin-secret',
    })
  })
})

describe('deleteBlockedBy', () => {
  it('returns the payload for a reassignment 422', () => {
    const blocked = deleteBlockedBy(
      unprocessable({
        message: 'This user still has 2 tickets.',
        ticket_count: 2,
        reassign_to_options: [heir],
      }),
    )
    expect(blocked).toEqual({
      message: 'This user still has 2 tickets.',
      ticket_count: 2,
      reassign_to_options: [heir],
    })
  })

  it('returns null for a 422 without the reassignment keys', () => {
    // The last-admin refusal: a 422, but not one a destination can fix.
    expect(
      deleteBlockedBy(
        unprocessable({
          message: 'This is the last active administrator.',
          errors: { user: ['This is the last active administrator.'] },
        }),
      ),
    ).toBeNull()
  })

  it('returns null for a 403 and for a non-Axios error', () => {
    const forbidden = new AxiosError('Forbidden')
    forbidden.response = {
      data: { message: 'This action is unauthorized.' },
      status: 403,
      statusText: 'Forbidden',
      headers: {},
      config: {} as InternalAxiosRequestConfig,
    }
    expect(deleteBlockedBy(forbidden)).toBeNull()
    expect(deleteBlockedBy(new Error('boom'))).toBeNull()
  })
})
