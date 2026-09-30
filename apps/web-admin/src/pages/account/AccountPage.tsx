import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { ErrorBanner, SuccessBanner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { QrCode } from '../../components/QrCode';
import { useChangePassword, useTwoFactorConfirm, useTwoFactorDisable, useTwoFactorSetup } from '../../features/account';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';

const fieldError = (error: unknown, name: string) => (error instanceof ApiError ? error.fieldError(name) : undefined);
const hasFieldErrors = (error: unknown) => error instanceof ApiError && Object.keys(error.fields).length > 0;

/** Own account: password + optional two-factor login (SECURITY.md §1a). */
export function AccountPage() {
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <PasswordCard />
      <TwoFactorCard />
    </div>
  );
}

function PasswordCard() {
  const change = useChangePassword();
  const [form, setForm] = useState({ currentPassword: '', password: '' });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    change.mutate(form, { onSuccess: () => setForm({ currentPassword: '', password: '' }) });
  };

  return (
    <Card title={t('account.password')}>
      <form onSubmit={submit} className="space-y-3" noValidate>
        <TextField label={t('account.currentPassword')} type="password" autoComplete="current-password" value={form.currentPassword} onChange={(e) => setForm({ ...form, currentPassword: e.target.value })} error={fieldError(change.error, 'currentPassword')} required />
        <TextField label={t('account.newPassword')} type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} error={fieldError(change.error, 'password')} required />
        {change.isError && !hasFieldErrors(change.error) && <ErrorBanner error={change.error} />}
        {change.isSuccess && <SuccessBanner>{t('account.passwordSaved')}</SuccessBanner>}
        <Button type="submit" loading={change.isPending}>{t('common.save')}</Button>
      </form>
    </Card>
  );
}

function TwoFactorCard() {
  const me = useMe();
  const setup = useTwoFactorSetup();
  const confirm = useTwoFactorConfirm();
  const disable = useTwoFactorDisable();
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [mode, setMode] = useState<'idle' | 'enable' | 'disable'>('idle');
  const enabled = me.data?.user.twoFactorEnabled ?? false;
  const recoveryCodes = confirm.data?.recoveryCodes;

  const reset = () => {
    setMode('idle');
    setPassword('');
    setCode('');
    setup.reset();
    disable.reset();
  };

  if (recoveryCodes) {
    return (
      <Card title={t('account.recoveryTitle')}>
        <p className="mb-3 text-sm text-slate-700">{t('account.recoveryHint')}</p>
        <ul className="mb-4 grid grid-cols-2 gap-2 font-mono text-base" aria-label={t('account.recoveryTitle')}>
          {recoveryCodes.map((c) => (
            <li key={c} className="rounded bg-slate-100 px-2 py-1 text-center">{c}</li>
          ))}
        </ul>
        <Button onClick={() => { confirm.reset(); reset(); }}>{t('account.recoveryDone')}</Button>
      </Card>
    );
  }

  return (
    <Card title={t('account.twoFactor')}>
      <p className={`mb-3 text-sm ${enabled ? 'text-emerald-700' : 'text-amber-700'}`}>{enabled ? t('account.twoFactorOn') : t('account.twoFactorOff')}</p>

      {mode === 'idle' && (
        <Button variant={enabled ? 'secondary' : 'primary'} onClick={() => setMode(enabled ? 'disable' : 'enable')}>
          {enabled ? t('account.disable') : t('account.enable')}
        </Button>
      )}

      {mode === 'enable' && !setup.data && (
        <form className="space-y-3" noValidate onSubmit={(e) => { e.preventDefault(); setup.mutate(password); }}>
          <TextField label={t('account.currentPassword')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fieldError(setup.error, 'password')} required />
          {setup.isError && !hasFieldErrors(setup.error) && <ErrorBanner error={setup.error} />}
          <div className="flex gap-2">
            <Button type="submit" loading={setup.isPending}>{t('common.next')}</Button>
            <Button type="button" variant="ghost" onClick={reset}>{t('common.cancel')}</Button>
          </div>
        </form>
      )}

      {mode === 'enable' && setup.data && (
        <form className="space-y-3 text-sm text-slate-700" noValidate onSubmit={(e) => { e.preventDefault(); confirm.mutate(code); }}>
          <p>{t('account.step1')}</p>
          <p>{t('account.step2')}</p>
          <a href={setup.data.uri} className="inline-flex min-h-11 items-center rounded-lg bg-brand-700 px-4 font-semibold text-white">{t('account.openApp')}</a>
          <QrCode text={setup.data.uri} label={t('account.twoFactor')} />
          <p>
            {t('account.secret')}: <span className="select-all break-all font-mono">{setup.data.secret.match(/.{1,4}/g)?.join(' ')}</span>
          </p>
          <p>{t('account.step3')}</p>
          <TextField label={t('auth.code')} inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} error={fieldError(confirm.error, 'code')} required />
          {confirm.isError && !hasFieldErrors(confirm.error) && <ErrorBanner error={confirm.error} />}
          <div className="flex gap-2">
            <Button type="submit" loading={confirm.isPending}>{t('common.confirm')}</Button>
            <Button type="button" variant="ghost" onClick={reset}>{t('common.cancel')}</Button>
          </div>
        </form>
      )}

      {mode === 'disable' && (
        <form className="space-y-3" noValidate onSubmit={(e) => { e.preventDefault(); disable.mutate({ password, code }, { onSuccess: reset }); }}>
          <TextField label={t('account.currentPassword')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fieldError(disable.error, 'password')} required />
          <TextField label={t('auth.code')} hint={t('auth.codeHint')} autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} error={fieldError(disable.error, 'code')} required />
          {disable.isError && !hasFieldErrors(disable.error) && <ErrorBanner error={disable.error} />}
          <div className="flex gap-2">
            <Button type="submit" variant="danger" loading={disable.isPending}>{t('account.disable')}</Button>
            <Button type="button" variant="ghost" onClick={reset}>{t('common.cancel')}</Button>
          </div>
        </form>
      )}
    </Card>
  );
}
