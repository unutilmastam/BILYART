import { BallMark, Centered, Spinner } from '../components/ui';
import type { PairingState } from '../features/pairing';
import { t } from '../i18n';

export function PairingScreen({ state }: { state: PairingState }) {
  if (state.phase !== 'waiting') {
    return (
      <Centered>
        <Spinner />
      </Centered>
    );
  }
  const expires = new Date(state.expiresAt).toLocaleTimeString('uz-UZ', { hour: '2-digit', minute: '2-digit', hour12: false });
  return (
    <Centered>
      <BallMark className="size-16" />
      <h1 className="text-4xl font-bold tracking-tight">{t('pairing.title')}</h1>
      <p className="max-w-2xl text-2xl text-white/75">{t('pairing.instructions')}</p>
      <div className="glass max-w-full rounded-[32px] px-6 py-6 sm:px-12 sm:py-8">
        <p className="text-xl text-white/60">{t('pairing.code')}</p>
        <p className="tabular mt-2 font-mono text-6xl font-bold tracking-[0.15em] text-accent-300 sm:text-8xl sm:tracking-[0.2em]" aria-label={t('pairing.code')}>
          {state.pairingCode}
        </p>
      </div>
      <p className="text-xl text-white/60">
        {t('pairing.device')}: <span className="font-mono text-white">{state.tabletCode}</span> · {t('pairing.expires', { time: expires })}
      </p>
      <p className="flex items-center gap-3 text-xl text-white/75">
        <Spinner /> {t('pairing.waiting')}
      </p>
    </Centered>
  );
}
