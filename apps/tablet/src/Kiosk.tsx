import type { TabletDurationQuote, TabletTable } from '@bilyart/protocol';
import { useCallback, useEffect, useRef, useState } from 'react';
import { BallMark, Banner, Centered, Spinner } from './components/ui';
import { useKiosk } from './features/kiosk';
import { sessionApi, waitForDevice, type Session } from './features/session';
import { useServerNow } from './features/useNow';
import { ApiError, newKey } from './lib/api';
import { formatClock } from './lib/format';
import { announce, warningText, WarningScheduler } from './lib/warnings';
import { t } from './i18n';
import { ConfirmScreen } from './screens/ConfirmScreen';
import { DurationScreen } from './screens/DurationScreen';
import { PaymentScreen } from './screens/PaymentScreen';
import { PhotoScreen, type PhotoDeps } from './screens/PhotoScreen';
import { StartScreen } from './screens/StartScreen';
import { canChoose, TablesScreen } from './screens/TablesScreen';

type Flow =
  | { step: 'tables' }
  | { step: 'duration'; table: TabletTable }
  | { step: 'confirm'; table: TabletTable; quote: TabletDurationQuote; key: string }
  | { step: 'photo'; session: Session; attempt: number; pricePerHour: number }
  | { step: 'pay'; session: Session; pricePerHour: number }
  | { step: 'start'; session: Session | null; key: string };

/** Unattended flow steps fall back to the table list (and free a reservation) after this. */
const IDLE_MS = 60_000;
const DONE_RETURN_MS = 10_000;
/** The bill acceptor payment is watched this often (the server counts the money). */
const PAY_POLL_MS = 1500;

const message = (e: unknown) => (e instanceof ApiError ? e.message : "Nimadir xato ketdi. Qayta urinib ko'ring.");

export function Kiosk({ onUnpaired, photoDeps }: { onUnpaired: () => void; photoDeps?: PhotoDeps }) {
  const { bootstrap, connection, refresh } = useKiosk(onUnpaired);
  const now = useServerNow();
  const [flow, setFlow] = useState<Flow>({ step: 'tables' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lastTouch = useRef(Date.now());
  const scheduler = useRef<WarningScheduler | null>(null);

  const online = connection === 'online';
  const reservation = flow.step === 'photo' ? flow.session : null;

  const reset = useCallback(
    (cancel?: Session | null) => {
      if (cancel && cancel.status === 'RESERVED') void sessionApi.cancel(cancel.id, newKey()).catch(() => undefined);
      setBusy(false);
      setError(null);
      setFlow({ step: 'tables' });
      void refresh();
    },
    [refresh],
  );

  // Idle timeout: a customer who walks away must not keep a table reserved.
  useEffect(() => {
    const touch = () => (lastTouch.current = Date.now());
    window.addEventListener('pointerdown', touch);
    const id = setInterval(() => {
      if (flow.step === 'tables' || flow.step === 'pay' || busy) return; // paying: the server's payment window decides
      if (flow.step === 'start' && !error && (!flow.session || flow.session.status === 'STARTING')) return; // still waiting for the table
      const limit = flow.step === 'start' ? DONE_RETURN_MS : IDLE_MS;
      if (Date.now() - lastTouch.current > limit) reset(reservation);
    }, 1000);
    return () => {
      window.removeEventListener('pointerdown', touch);
      clearInterval(id);
    };
  }, [flow, busy, error, reservation, reset]);

  // Five-minute warnings, decided locally from endAt + server offset (spec §12).
  useEffect(() => {
    if (!bootstrap) return;
    scheduler.current ??= new WarningScheduler(bootstrap.settings.warnBeforeSec);
    for (const table of scheduler.current.due(bootstrap.tables, now)) {
      void announce(warningText(bootstrap.settings.warningAudio.text, table));
    }
  }, [bootstrap, now]);

  // Bill acceptor payment: follow the money the server counted; the server also starts the lamp.
  const payingId = flow.step === 'pay' ? flow.session.id : null;
  useEffect(() => {
    if (!payingId) return;
    let stopped = false;
    const tick = async () => {
      let s: Session;
      try {
        s = await sessionApi.show(payingId);
      } catch {
        return; // short network drop: keep watching, the server keeps the money safe
      }
      if (stopped) return;
      if (s.status === 'RESERVED') {
        setFlow((f) => (f.step === 'pay' && f.session.id === s.id ? { ...f, session: s } : f));
        return;
      }
      const key = newKey();
      if (s.status === 'CANCELLED') setError(t('pay.cancelled'));
      setFlow({ step: 'start', session: s, key });
      const final = s.status === 'STARTING' ? await waitForDevice(s.id).catch(() => s) : s;
      lastTouch.current = Date.now();
      setFlow((f) => (f.step === 'start' && f.key === key ? { step: 'start', session: final, key } : f));
    };
    const id = setInterval(() => void tick(), PAY_POLL_MS);
    return () => {
      stopped = true;
      clearInterval(id);
    };
  }, [payingId]);

  if (!bootstrap) {
    return (
      <Centered>
        <Spinner />
        {connection === 'offline' && <p className="text-2xl">{t('offline.banner')}</p>}
      </Centered>
    );
  }

  if (connection === 'suspended') {
    return (
      <Centered>
        <p className="text-5xl font-bold">{t('suspended.title')}</p>
        <p className="text-2xl text-white/70">{t('suspended.body')}</p>
      </Centered>
    );
  }

  const { branch, settings } = bootstrap;
  const cashMode = branch.paymentMode === 'BILL_ACCEPTOR';
  const cashDown = cashMode && branch.cashOnline === false;

  const confirm = async (f: Extract<Flow, { step: 'confirm' }>) => {
    setBusy(true);
    setError(null);
    try {
      const session = await sessionApi.prepare(f.table.id, f.quote.minutes, f.key);
      lastTouch.current = Date.now();
      setFlow({ step: 'photo', session, attempt: 0, pricePerHour: f.table.pricing?.pricePerHour ?? 0 });
    } catch (e) {
      setError(message(e));
      void refresh();
    } finally {
      setBusy(false);
    }
  };

  /** Lamp is starting (or has started): show the countdown once the table device confirmed. */
  const follow = async (started: Session, key: string) => {
    setFlow({ step: 'start', session: started, key });
    const final = started.status === 'STARTING' ? await waitForDevice(started.id) : started;
    lastTouch.current = Date.now();
    setFlow({ step: 'start', session: final, key });
  };

  const start = async (session: Session, pricePerHour: number) => {
    const key = newKey();
    setFlow({ step: 'start', session: null, key });
    try {
      const started = await sessionApi.start(session.id, key);
      if (started.status === 'RESERVED' && started.payment) {
        setFlow({ step: 'pay', session: started, pricePerHour }); // bill acceptor branch: the customer pays first
        return;
      }
      await follow(started, key);
    } catch (e) {
      setError(message(e));
    } finally {
      void refresh();
    }
  };

  /** "Cancel" / "play for what I paid": the acceptor stops; nothing paid → back to the tables. */
  const stopPaying = async (session: Session, pricePerHour: number) => {
    setBusy(true);
    setError(null);
    try {
      const closed = await sessionApi.cancel(session.id, newKey());
      if ((closed.payment?.paid ?? 0) === 0) {
        reset();
        return;
      }
      setFlow({ step: 'pay', session: closed, pricePerHour });
    } catch (e) {
      setError(message(e));
    } finally {
      setBusy(false);
    }
  };

  const uploadPhoto = async (session: Session, photo: Blob, attempt: number) => {
    setBusy(true);
    setError(null);
    try {
      const updated = await sessionApi.uploadPhoto(session.id, photo, newKey());
      setBusy(false);
      await start(updated, flow.step === 'photo' ? flow.pricePerHour : 0);
    } catch (e) {
      setBusy(false);
      setError(message(e));
      setFlow({ step: 'photo', session, attempt: attempt + 1, pricePerHour: flow.step === 'photo' ? flow.pricePerHour : 0 }); // new camera round
    }
  };

  let screen;
  switch (flow.step) {
    case 'tables':
      screen = <TablesScreen tables={bootstrap.tables} now={now} online={online && !cashDown} onChoose={(table) => canChoose(table, online && !cashDown) && setFlow({ step: 'duration', table })} />;
      break;
    case 'duration':
      screen = <DurationScreen table={flow.table} onBack={() => reset()} onPick={(quote) => setFlow({ step: 'confirm', table: flow.table, quote, key: newKey() })} />;
      break;
    case 'confirm':
      screen = (
        <ConfirmScreen
          table={flow.table}
          quote={flow.quote}
          busy={busy}
          error={error}
          privacyNotice={settings.privacyNotice}
          payNote={cashMode ? t('confirm.payNoteCash') : t('confirm.payNote')}
          onBack={() => {
            setError(null);
            setFlow({ step: 'duration', table: flow.table });
          }}
          onConfirm={() => void confirm(flow)}
        />
      );
      break;
    case 'photo':
      screen = (
        <PhotoScreen
          key={flow.attempt}
          uploading={busy}
          error={error}
          deps={photoDeps}
          onPhoto={(photo) => void uploadPhoto(flow.session, photo, flow.attempt)}
          onRetry={() => {
            setError(null);
            lastTouch.current = Date.now();
            setFlow({ step: 'photo', session: flow.session, attempt: flow.attempt + 1, pricePerHour: flow.pricePerHour }); // fresh camera + detector
          }}
          onCancel={() => reset(flow.session)}
        />
      );
      break;
    case 'pay':
      screen = <PaymentScreen session={flow.session} pricePerHour={flow.pricePerHour} now={now} busy={busy} error={error} onStop={() => void stopPaying(flow.session, flow.pricePerHour)} />;
      break;
    case 'start':
      screen = <StartScreen session={flow.session} now={now} timezone={branch.timezone} error={error} onDone={() => reset()} />;
      break;
  }

  return (
    <div className="flex h-full flex-col">
      <header className="flex items-center justify-between gap-4 border-b border-white/10 bg-black/20 px-6 py-3 backdrop-blur lg:px-8">
        <span className="flex min-w-0 items-center gap-3">
          <BallMark />
          <span className="min-w-0 leading-tight">
            <span className="block truncate text-xl font-bold tracking-tight">{branch.tenantName}</span>
            <span className="block truncate text-base text-brand-200/80">{branch.name}</span>
          </span>
        </span>
        <span className="glass tabular rounded-full px-5 py-1.5 text-2xl font-semibold">{formatClock(now, branch.timezone)}</span>
      </header>
      {!online && <Banner tone="warn">{t('offline.banner')}</Banner>}
      {online && cashDown && flow.step === 'tables' && <Banner tone="warn">{t('cash.offline')}</Banner>}
      <main className="relative flex-1 overflow-hidden">
        {!branch.isOpenNow && flow.step === 'tables' ? (
          <Centered>
            <p className="text-5xl font-bold">{t('closed.title')}</p>
            <p className="text-2xl text-white/70">{t('closed.body')}</p>
          </Centered>
        ) : (
          screen
        )}
      </main>
    </div>
  );
}
