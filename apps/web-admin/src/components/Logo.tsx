import { useId } from 'react';
import { t } from '../i18n';

/** Brand mark: a cue ball with a brass ring on felt green. */
export function LogoMark({ className = 'size-9' }: { className?: string }) {
  // Unique gradient ids per instance: a hidden copy (e.g. the desktop sidebar on a phone) must not own them.
  const uid = useId().replace(/:/g, '');
  const felt = `bl-felt-${uid}`;
  const ball = `bl-ball-${uid}`;
  return (
    <svg viewBox="0 0 40 40" className={className} aria-hidden>
      <defs>
        <linearGradient id={felt} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#14ae80" />
          <stop offset="1" stopColor="#0a4a3b" />
        </linearGradient>
        <radialGradient id={ball} cx="0.38" cy="0.32" r="0.75">
          <stop offset="0" stopColor="#ffffff" />
          <stop offset="1" stopColor="#dfe7e4" />
        </radialGradient>
      </defs>
      <rect width="40" height="40" rx="11" fill={`url(#${felt})`} />
      <circle cx="20" cy="20" r="11" fill={`url(#${ball})`} />
      <circle cx="20" cy="20" r="5.2" fill="none" stroke="#ffb520" strokeWidth="2.4" />
      <circle cx="16" cy="15.5" r="2" fill="#ffffff" opacity="0.9" />
    </svg>
  );
}

export function Logo({ subtitle, tone = 'light' }: { subtitle?: string; tone?: 'light' | 'dark' }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <LogoMark />
      <div className="min-w-0 leading-tight">
        <p className={`truncate text-[17px] font-bold tracking-tight ${tone === 'light' ? 'text-white' : 'text-slate-900'}`}>{t('app.title')}</p>
        {subtitle && <p className={`truncate text-xs ${tone === 'light' ? 'text-brand-200/80' : 'text-slate-500'}`}>{subtitle}</p>}
      </div>
    </div>
  );
}
