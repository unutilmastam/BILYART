import { t } from '../i18n';
import { ApiError } from '../lib/api';

export function Spinner({ label = t('common.loading') }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-2 py-10 text-slate-500">
      <span className="size-5 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" aria-hidden />
      {label}
    </div>
  );
}

export function ErrorBanner({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof ApiError ? error.message : "Nimadir xato ketdi. Qayta urinib ko'ring.";
  // Server-side failures carry a request id that matches the server log line — shown so support can find it.
  const requestId = error instanceof ApiError && error.status >= 500 ? error.requestId : undefined;
  return (
    <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
      <span>
        {message}
        {requestId && <span className="block font-mono text-xs text-red-700/80">{t('common.requestId')}: {requestId}</span>}
      </span>
      {onRetry && (
        <button type="button" className="font-semibold underline" onClick={onRetry}>
          {t('common.retry')}
        </button>
      )}
    </div>
  );
}

export function SuccessBanner({ children }: { children: React.ReactNode }) {
  return <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{children}</div>;
}

export function Empty() {
  return (
    <div className="flex flex-col items-center gap-2 py-10 text-center">
      <span className="grid size-11 place-items-center rounded-2xl bg-slate-100 text-slate-400" aria-hidden>
        <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M22 12h-6l-2 3h-4l-2-3H2" />
          <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
        </svg>
      </span>
      <p className="text-sm text-slate-500">{t('common.empty')}</p>
    </div>
  );
}
