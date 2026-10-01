import '@fontsource-variable/inter';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './index.css';

// Keep the kiosk screen awake (released by the browser when hidden; re-acquired on return).
async function keepAwake() {
  try {
    await navigator.wakeLock?.request('screen');
  } catch {
    // not supported / not allowed — Android display timeout must then be set to "never" (TABLET_SETUP.md)
  }
}
void keepAwake();
document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && void keepAwake());

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
