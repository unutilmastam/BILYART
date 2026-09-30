import type { ButtonHTMLAttributes, ReactNode } from 'react';

export function BigButton({ variant = 'primary', className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' | 'danger' }) {
  const styles = {
    primary: 'bg-brand-500 text-slate-950 active:bg-brand-400 disabled:bg-slate-700 disabled:text-slate-400',
    ghost: 'bg-slate-800 text-white active:bg-slate-700 disabled:opacity-50',
    danger: 'bg-red-600 text-white active:bg-red-500',
  }[variant];
  return <button type="button" {...rest} className={`min-h-20 rounded-2xl px-8 text-2xl font-bold transition ${styles} ${className}`} />;
}

export function Centered({ children }: { children: ReactNode }) {
  return <div className="flex h-full flex-col items-center justify-center gap-6 p-8 text-center">{children}</div>;
}

export function Spinner() {
  return <span className="inline-block size-12 animate-spin rounded-full border-4 border-brand-500 border-t-transparent" aria-hidden />;
}

export function Banner({ tone, children }: { tone: 'warn' | 'error'; children: ReactNode }) {
  return (
    <div role="alert" className={`px-6 py-3 text-center text-xl font-semibold ${tone === 'warn' ? 'bg-amber-500 text-slate-950' : 'bg-red-600 text-white'}`}>
      {children}
    </div>
  );
}
