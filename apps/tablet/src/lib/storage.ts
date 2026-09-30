/**
 * Tiny IndexedDB key-value store. Holds the tablet credential and a read-only
 * copy of the last bootstrap for offline display — never business state
 * (the server is authoritative; CLAUDE.md: no browser storage as a database).
 */
const DB = 'bilyart-kiosk';
const STORE = 'kv';

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function run<T>(mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest): Promise<T> {
  const db = await open();
  try {
    return await new Promise<T>((resolve, reject) => {
      const req = fn(db.transaction(STORE, mode).objectStore(STORE));
      req.onsuccess = () => resolve(req.result as T);
      req.onerror = () => reject(req.error);
    });
  } finally {
    db.close();
  }
}

export const storage = {
  get: <T>(key: string) => run<T | undefined>('readonly', (s) => s.get(key)),
  set: (key: string, value: unknown) => run<void>('readwrite', (s) => s.put(value, key)),
  del: (key: string) => run<void>('readwrite', (s) => s.delete(key)),
};

export const KEYS = {
  token: 'tabletToken',
  pairing: 'pairing',
  bootstrap: 'bootstrapCache',
} as const;
