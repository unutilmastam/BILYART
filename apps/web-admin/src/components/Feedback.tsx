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
  return (
    <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
      <span>{message}</span>
      {onRetry && (
        <button type="button" className="font-semibold underline" onClick={onRetry}>
          {t('common.retry')}
        </button>
      )}
    </div>
  );
}

export function SuccessBanner({ children }: { children: React.ReactNode }) {
  return <div role="status" className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">{children}</div>;
}

export function Empty() {
  return <p className="py-8 text-center text-sm text-slate-500">{t('common.empty')}</p>;
}
