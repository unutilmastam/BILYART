import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router';
import { ME_KEY, useMe } from '../auth/useMe';
import { Button } from '../components/Button';
import { ErrorBanner } from '../components/Feedback';
import { TextField } from '../components/Field';
import { t } from '../i18n';
import { api, ApiError } from '../lib/api';
import type { Me } from '../types/api';

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
    <div className="flex min-h-dvh items-center justify-center bg-brand-800 px-4">
      <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-xl" noValidate>
        <div>
          <h1 className="text-xl font-bold text-slate-900">{t('app.title')}</h1>
          <p className="text-sm text-slate-500">{t('auth.welcome')}</p>
        </div>
        {mutation.isError && !fieldError('login') && !fieldError('password') && <ErrorBanner error={mutation.error} />}
        <TextField label={t('auth.login')} autoComplete="username" autoCapitalize="none" value={login} onChange={(e) => setLogin(e.target.value)} error={fieldError('login')} required />
        <TextField label={t('auth.password')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fieldError('password')} required />
        {needsCode && (
          <TextField label={t('auth.code')} hint={t('auth.codeHint')} inputMode="numeric" autoComplete="one-time-code" autoFocus value={code} onChange={(e) => setCode(e.target.value)} required />
        )}
        <Button type="submit" className="w-full" loading={mutation.isPending}>
          {t('auth.signIn')}
        </Button>
      </form>
    </div>
  );
}
