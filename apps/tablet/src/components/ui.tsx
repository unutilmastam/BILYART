import type { ButtonHTMLAttributes, ReactNode } from 'react';

export function BigButton({ variant = 'primary', className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' | 'danger' }) {
  const styles = {
    primary:
      'bg-gradient-to-b from-brand-400 to-brand-600 text-white shadow-[0_10px_30px_-10px_rgb(20_174_128/0.8)] ring-1 ring-inset ring-white/20 active:from-brand-500 active:to-brand-700 disabled:from-slate-600 disabled:to-slate-700 disabled:text-slate-300 disabled:shadow-none',
    ghost: 'glass text-white active:bg-white/15 disabled:opacity-50',
    danger: 'bg-gradient-to-b from-red-500 to-red-700 text-white active:from-red-600',
  }[variant];
  return <button type="button" {...rest} className={`inline-flex min-h-20 items-center justify-center gap-3 rounded-2xl px-10 text-2xl font-bold tracking-tight transition active:scale-[0.98] ${styles} ${className}`} />;
}

export function Centered({ children }: { children: ReactNode }) {
  return <div className="flex h-full flex-col items-center justify-center gap-6 p-8 text-center">{children}</div>;
}

export function Spinner() {
  return <span className="inline-block size-12 animate-spin rounded-full border-4 border-brand-400 border-t-transparent" aria-hidden />;
}

export function Banner({ tone, children }: { tone: 'warn' | 'error'; children: ReactNode }) {
  return (
    <div role="alert" className={`px-6 py-3 text-center text-xl font-semibold ${tone === 'warn' ? 'bg-accent-400 text-ink' : 'bg-red-600 text-white'}`}>
      {children}
    </div>
  );
}

/** Screen title with an optional step hint (e.g. "2 / 4"). */
export function ScreenTitle({ children, step }: { children: ReactNode; step?: string }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <h1 className="text-4xl font-bold tracking-tight">{children}</h1>
      {step && <span className="glass rounded-full px-4 py-1.5 text-lg font-semibold text-brand-200">{step}</span>}
    </div>
  );
}

/** Cue-ball mark used in the header. */
export function BallMark({ className = 'size-10' }: { className?: string }) {
  return (
    <svg viewBox="0 0 40 40" className={className} aria-hidden>
      <circle cx="20" cy="21" r="17" fill="#000" opacity="0.3" />
      <circle cx="20" cy="20" r="17" fill="#f4f7f6" />
      <circle cx="20" cy="20" r="8" fill="none" stroke="#ffb520" strokeWidth="3.2" />
      <circle cx="14" cy="13" r="3.2" fill="#fff" />
    </svg>
  );
}

/** Circular countdown: fraction 0..1 of the session left. */
export function Ring({ fraction, size = 320, stroke = 14, tone = 'brand', children }: { fraction: number; size?: number; stroke?: number; tone?: 'brand' | 'warn'; children: ReactNode }) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const f = Math.min(1, Math.max(0, fraction));
  return (
    <div className="relative grid place-items-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="absolute inset-0 -rotate-90" aria-hidden>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgb(255 255 255 / 0.08)" strokeWidth={stroke} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={tone === 'warn' ? '#ffb520' : '#38c999'}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={c * (1 - f)}
          style={{ transition: 'stroke-dashoffset 1s linear' }}
        />
      </svg>
      <div className="relative flex flex-col items-center">{children}</div>
    </div>
  );
}
