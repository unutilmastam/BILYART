import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router';
import { ME_KEY, useMe } from '../auth/useMe';
import { Button } from '../components/Button';
import { ErrorBanner } from '../components/Feedback';
import { Logo, LogoMark } from '../components/Logo';
import { TextField } from '../components/Field';
import { t } from '../i18n';
import { api, ApiError } from '../lib/api';
import type { Me } from '../types/api';

/** Decorative billiard balls for the login hero (pure SVG, no external assets). */
function BallsArt() {
  const ball = (cx: number, cy: number, r: number, fill: string, label?: string) => (
    <g>
      <circle cx={cx + r * 0.12} cy={cy + r * 0.18} r={r} fill="#000" opacity="0.25" />
      <circle cx={cx} cy={cy} r={r} fill={fill} />
      {label && (
        <>
          <circle cx={cx} cy={cy} r={r * 0.45} fill="#fff" />
          <text x={cx} y={cy + r * 0.17} textAnchor="middle" fontSize={r * 0.5} fontWeight="700" fill="#0f172a" fontFamily="Inter Variable, sans-serif">
            {label}
          </text>
        </>
      )}
      <circle cx={cx - r * 0.35} cy={cy - r * 0.4} r={r * 0.22} fill="#fff" opacity="0.55" />
    </g>
  );
  return (
    <svg className="pointer-events-none absolute -bottom-10 -right-10 h-[420px] w-[520px]" viewBox="0 0 520 420" aria-hidden>
      <circle cx="380" cy="330" r="210" fill="none" stroke="#fff" strokeOpacity="0.06" strokeWidth="2" />
      <circle cx="380" cy="330" r="150" fill="none" stroke="#fff" strokeOpacity="0.05" strokeWidth="2" />
      {ball(300, 250, 46, '#0b0f0e', '8')}
      {ball(400, 170, 34, '#ffb520', '1')}
      {ball(190, 330, 30, '#f4f6f5')}
      {ball(430, 320, 40, '#b4232a', '3')}
    </svg>
  );
}

export function LoginPage() {
  const me = useMe();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  // Shown once the server says this account uses two-factor login; stays visible after a wrong code.
  const [needsCode, setNeedsCode] = useState(false);

  const mutation = useMutation({
    mutationFn: () => api<Me>('/auth/login', { method: 'POST', body: code ? { login, password, code } : { login, password } }),
    onSuccess: (data) => {
      qc.setQueryData(ME_KEY, data);
      navigate(data.user.role === 'SUPER_ADMIN' ? '/super' : '/client', { replace: true });
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code.startsWith('TWO_FACTOR_')) setNeedsCode(true);
    },
  });

  if (me.data) return <Navigate to="/" replace />;

  const fieldError = (name: string) => (mutation.error instanceof ApiError ? mutation.error.fieldError(name) : undefined);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    mutation.mutate();
  };

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
      {/* Brand panel (desktop) */}
      <aside className="felt relative hidden overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <Logo subtitle={t('auth.tagline')} />
        <div className="relative z-10 max-w-md">
          <p className="text-4xl font-bold leading-tight tracking-tight">{t('auth.heroTitle')}</p>
          <p className="mt-4 text-base text-brand-100/80">{t('auth.heroText')}</p>
        </div>
        <p className="relative z-10 text-xs text-brand-200/60">© {new Date().getFullYear()} {t('app.title')}</p>
        <BallsArt />
      </aside>

      <div className="flex items-center justify-center bg-canvas px-4 py-10">
        <form onSubmit={submit} className="w-full max-w-sm space-y-5" noValidate>
          <div className="flex flex-col items-center gap-3 text-center lg:items-start lg:text-left">
            <LogoMark className="size-12 lg:hidden" />
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-slate-900">{t('auth.welcome')}</h1>
              <p className="mt-1 text-sm text-slate-500">{t('auth.subtitle')}</p>
            </div>
          </div>
          <div className="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-card">
            {mutation.isError && !fieldError('login') && !fieldError('password') && <ErrorBanner error={mutation.error} />}
            <TextField label={t('auth.login')} autoComplete="username" autoCapitalize="none" value={login} onChange={(e) => setLogin(e.target.value)} error={fieldError('login')} required />
            <TextField label={t('auth.password')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fieldError('password')} required />
            {needsCode && (
              <TextField label={t('auth.code')} hint={t('auth.codeHint')} inputMode="numeric" autoComplete="one-time-code" autoFocus value={code} onChange={(e) => setCode(e.target.value)} required />
            )}
            <Button type="submit" className="w-full" loading={mutation.isPending}>
              {t('auth.signIn')}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
