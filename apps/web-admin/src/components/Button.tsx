import type { ButtonHTMLAttributes } from 'react';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';

const styles: Record<Variant, string> = {
  primary:
    'bg-gradient-to-b from-brand-600 to-brand-700 text-white shadow-sm shadow-brand-900/20 ring-1 ring-inset ring-white/10 hover:from-brand-500 hover:to-brand-700 active:to-brand-800 disabled:from-brand-600/50 disabled:to-brand-700/50 disabled:shadow-none',
  secondary: 'bg-white text-slate-800 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 hover:ring-slate-400 disabled:opacity-50',
  danger: 'bg-gradient-to-b from-red-500 to-red-600 text-white shadow-sm shadow-red-900/20 hover:from-red-500 hover:to-red-700 disabled:opacity-50',
  ghost: 'text-slate-700 hover:bg-slate-100 disabled:opacity-50',
};

export function Button({
  variant = 'primary',
  loading = false,
  className = '',
  children,
  disabled,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; loading?: boolean }) {
  return (
    <button
      {...rest}
      disabled={disabled || loading}
      className={`inline-flex min-h-11 select-none items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold transition duration-150 disabled:cursor-not-allowed ${styles[variant]} ${className}`}
    >
      {loading && <span className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden />}
      {children}
    </button>
  );
}
