import type { TabletPairingStatusResponse, TabletRegisterResponse } from '@bilyart/protocol';
import { useEffect, useState } from 'react';
import { api, ApiError, setToken } from '../lib/api';
import { KEYS, storage } from '../lib/storage';

export const APP_VERSION = '0.1.0';
const POLL_MS = 3000;

export type PairingState =
  | { phase: 'loading' }
  | { phase: 'waiting'; tabletCode: string; pairingCode: string; expiresAt: string }
  | { phase: 'paired' };

/** Loads the stored credential or runs the pairing handshake (protocol: tablet.register / tablet.pairing-status). */
export function usePairing(): { state: PairingState; unpair: () => Promise<void> } {
  const [state, setState] = useState<PairingState>({ phase: 'loading' });
  const [generation, setGeneration] = useState(0);

  useEffect(() => {
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const register = async (): Promise<TabletRegisterResponse & { savedAt: number }> => {
      const r = await api<TabletRegisterResponse>('/tablet/register', {
        method: 'POST',
        body: { appVersion: APP_VERSION, deviceModel: navigator.userAgent.slice(0, 100) },
      });
      const saved = { ...r, savedAt: Date.now() };
      await storage.set(KEYS.pairing, saved);
      return saved;
    };

    const run = async () => {
      const token = await storage.get<string>(KEYS.token);
      if (token) {
        setToken(token);
        if (!stopped) setState({ phase: 'paired' });
        return;
      }
      let pairing = await storage.get<TabletRegisterResponse>(KEYS.pairing);
      const poll = async (): Promise<void> => {
        if (stopped) return;
        try {
          pairing ??= await register();
          if (!stopped) setState({ phase: 'waiting', tabletCode: pairing.tabletCode, pairingCode: pairing.pairingCode, expiresAt: pairing.pairingExpiresAt });
          const status = await api<TabletPairingStatusResponse>('/tablet/pairing-status', { authorization: `PollToken ${pairing.pollToken}` });
          if (status.status === 'PAIRED' && status.token) {
            await storage.set(KEYS.token, status.token);
            await storage.del(KEYS.pairing);
            setToken(status.token);
            if (!stopped) setState({ phase: 'paired' });
            return;
          }
          if (status.status === 'EXPIRED') {
            await storage.del(KEYS.pairing);
            pairing = undefined;
          }
        } catch (e) {
          // An unknown/expired poll token starts over; network errors just retry.
          if (e instanceof ApiError && !e.isNetwork && e.status < 500) {
            await storage.del(KEYS.pairing);
            pairing = undefined;
          }
        }
        timer = setTimeout(poll, POLL_MS);
      };
      await poll();
    };

    void run();
    return () => {
      stopped = true;
      clearTimeout(timer);
    };
  }, [generation]);

  const unpair = async () => {
    setToken(null);
    await storage.del(KEYS.token);
    await storage.del(KEYS.bootstrap);
    setState({ phase: 'loading' });
    setGeneration((g) => g + 1);
  };

  return { state, unpair };
}
