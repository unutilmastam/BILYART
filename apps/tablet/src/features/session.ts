import type { TabletSession } from '@bilyart/protocol';
import { api } from '../lib/api';

export type Session = TabletSession['session'];

/** Customer flow calls (ARCHITECTURE §5). Each gets one idempotency key per logical action. */
export const sessionApi = {
  prepare: (tableId: string, durationMinutes: number, key: string) =>
    api<TabletSession>('/tablet/sessions/prepare', { method: 'POST', body: { tableId, durationMinutes }, idempotencyKey: key }).then((r) => r.session),

  uploadPhoto: (id: string, photo: Blob, key: string) => {
    const form = new FormData();
    form.append('photo', photo, 'photo.jpg');
    return api<TabletSession>(`/tablet/sessions/${id}/photo`, { method: 'POST', form, idempotencyKey: key }).then((r) => r.session);
  },

  start: (id: string, key: string) => api<TabletSession>(`/tablet/sessions/${id}/start`, { method: 'POST', body: {}, idempotencyKey: key }).then((r) => r.session),

  cancel: (id: string, key: string) => api<TabletSession>(`/tablet/sessions/${id}/cancel`, { method: 'POST', body: {}, idempotencyKey: key }).then((r) => r.session),

  show: (id: string) => api<TabletSession>(`/tablet/sessions/${id}`).then((r) => r.session),
};

/** Polls until the table device confirmed (ACTIVE) or the start failed. */
export async function waitForDevice(id: string, opts: { intervalMs?: number; timeoutMs?: number; signal?: AbortSignal } = {}): Promise<Session> {
  const interval = opts.intervalMs ?? 2000;
  const deadline = Date.now() + (opts.timeoutMs ?? 45_000);
  for (;;) {
    let s: Session | null = null;
    try {
      s = await sessionApi.show(id);
    } catch {
      // keep waiting through short network drops; the server decides the outcome
    }
    if (s && s.status !== 'STARTING') return s;
    if (opts.signal?.aborted || Date.now() > deadline) {
      if (s) return s;
      throw new Error('timeout');
    }
    await new Promise((r) => setTimeout(r, interval));
  }
}
