import { useEffect, useState } from 'react';
import { clock } from '../lib/clock';

/** Server time (epoch ms), re-rendering every `intervalMs`. */
export function useServerNow(intervalMs = 1000): number {
  const [now, setNow] = useState(() => clock.now());
  useEffect(() => {
    const id = setInterval(() => setNow(clock.now()), intervalMs);
    return () => clearInterval(id);
  }, [intervalMs]);
  return now;
}
