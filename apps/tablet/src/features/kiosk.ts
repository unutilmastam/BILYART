import type { TabletBootstrapResponse, TabletTablesResponse } from '@bilyart/protocol';
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../lib/api';
import { KEYS, storage } from '../lib/storage';
import { APP_VERSION } from './pairing';

const TABLES_POLL_MS = 4000;
const HEARTBEAT_MS = 60_000;

export type Connection = 'online' | 'offline' | 'suspended';

export interface KioskData {
  bootstrap: TabletBootstrapResponse | null;
  connection: Connection;
  /** Re-reads the table list right away (after an action). */
  refresh: () => Promise<void>;
}

/**
 * Live kiosk data: bootstrap once, then the table list every few seconds.
 * The last good bootstrap (tables included) is cached in IndexedDB for
 * *display only* while the network is down (spec §38).
 */
export function useKiosk(onUnpaired: () => void): KioskData {
  const [bootstrap, setBootstrap] = useState<TabletBootstrapResponse | null>(null);
  const [connection, setConnection] = useState<Connection>('online');
  const current = useRef<TabletBootstrapResponse | null>(null);
  const unpaired = useRef(onUnpaired);
  unpaired.current = onUnpaired;

  const handleError = useCallback((e: unknown) => {
    if (!(e instanceof ApiError)) return setConnection('offline');
    if (e.status === 401 || e.status === 403) return unpaired.current(); // token revoked / tablet removed
    if (e.status === 402) return setConnection('suspended');
    setConnection('offline');
  }, []);

  const save = useCallback((b: TabletBootstrapResponse) => {
    current.current = b;
    setBootstrap(b);
    void storage.set(KEYS.bootstrap, b).catch(() => undefined);
  }, []);

  const loadBootstrap = useCallback(async () => {
    try {
      save(await api<TabletBootstrapResponse>('/tablet/bootstrap'));
      setConnection('online');
    } catch (e) {
      handleError(e);
    }
  }, [handleError, save]);

  const refresh = useCallback(async () => {
    if (!current.current) return loadBootstrap();
    try {
      const r = await api<TabletTablesResponse>('/tablet/tables');
      const b = current.current;
      save({ ...b, serverTime: r.serverTime, tables: r.tables, branch: { ...b.branch, isOpenNow: r.isOpenNow, cashOnline: r.cashOnline ?? b.branch.cashOnline } });
      setConnection('online');
    } catch (e) {
      handleError(e);
    }
  }, [handleError, loadBootstrap, save]);

  useEffect(() => {
    let stopped = false;
    void storage
      .get<TabletBootstrapResponse>(KEYS.bootstrap)
      .then((cached) => {
        if (cached && !stopped && !current.current) {
          current.current = cached;
          setBootstrap(cached);
        }
      })
      .catch(() => undefined)
      .finally(() => void loadBootstrap());

    const tables = setInterval(() => void refresh(), TABLES_POLL_MS);
    const beat = () => void api('/tablet/heartbeat', { method: 'POST', body: { appVersion: APP_VERSION } }).catch(() => undefined);
    const heartbeat = setInterval(beat, HEARTBEAT_MS);
    // Settings (prices, texts) can change in the admin panel: reload the full bootstrap every 5 minutes.
    const reload = setInterval(() => void loadBootstrap(), 300_000);
    beat();
    return () => {
      stopped = true;
      clearInterval(tables);
      clearInterval(heartbeat);
      clearInterval(reload);
    };
  }, [loadBootstrap, refresh]);

  return { bootstrap, connection, refresh };
}
