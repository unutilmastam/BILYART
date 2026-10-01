import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function Card({
  title,
  description,
  actions,
  children,
  className = '',
}: {
  title?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={`rounded-2xl border border-slate-200/80 bg-white p-4 shadow-card sm:p-5 ${className}`}>
      {(title || actions) && (
        <header className="mb-4 flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            {title && <h2 className="text-[15px] font-semibold tracking-tight text-slate-900">{title}</h2>}
            {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
          </div>
          {actions}
        </header>
      )}
      {children}
    </section>
  );
}

type Tone = 'default' | 'brand' | 'accent' | 'info' | 'warn' | 'bad';

const chip: Record<Tone, string> = {
  default: 'bg-slate-100 text-slate-600',
  brand: 'bg-brand-50 text-brand-700 ring-1 ring-brand-100',
  accent: 'bg-accent-50 text-accent-600 ring-1 ring-accent-100',
  info: 'bg-sky-50 text-sky-700 ring-1 ring-sky-100',
  warn: 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
  bad: 'bg-red-50 text-red-700 ring-1 ring-red-100',
};
const valueColor: Record<Tone, string> = {
  default: 'text-slate-900',
  brand: 'text-slate-900',
  accent: 'text-slate-900',
  info: 'text-slate-900',
  warn: 'text-amber-700',
  bad: 'text-red-700',
};

/** KPI tile: label, big tabular value, optional icon chip. */
export function Stat({ label, value, tone = 'default', icon: Icon }: { label: string; value: ReactNode; tone?: Tone; icon?: LucideIcon }) {
  return (
    <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-4 shadow-card">
      <div className="flex items-start justify-between gap-2">
        <p className="text-[13px] font-medium leading-snug text-slate-500">{label}</p>
        {Icon && (
          <span className={`hidden size-9 shrink-0 place-items-center rounded-xl sm:grid ${chip[tone]}`}>
            <Icon className="size-[18px]" aria-hidden />
          </span>
        )}
      </div>
      <p className={`tabular mt-1 text-xl font-bold leading-tight tracking-tight [overflow-wrap:anywhere] sm:text-[28px] ${valueColor[tone]}`}>{value}</p>
    </div>
  );
}
