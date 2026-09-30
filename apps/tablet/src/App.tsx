import { Kiosk } from './Kiosk';
import { usePairing } from './features/pairing';
import type { PhotoDeps } from './screens/PhotoScreen';
import { PairingScreen } from './screens/PairingScreen';

export function App({ photoDeps }: { photoDeps?: PhotoDeps }) {
  const { state, unpair } = usePairing();
  if (state.phase !== 'paired') return <PairingScreen state={state} />;
  return <Kiosk onUnpaired={() => void unpair()} photoDeps={photoDeps} />;
}
