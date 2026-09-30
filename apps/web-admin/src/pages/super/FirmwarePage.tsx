import { useState, type FormEvent } from 'react';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { useFirmware, useFirmwareAction, useUploadFirmware, type FirmwareRelease, type RolloutResult } from '../../features/super/firmware';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';
import { formatDateTime } from '../../lib/format';

/** Super Admin → ESP32 firmware releases: upload (sha256 computed by the server), publish, OTA rollout. */
export function FirmwarePage() {
  const q = useFirmware();
  return (
    <div className="space-y-4">
      <Card title={t('fw.upload')}>
        <p className="mb-3 text-sm text-slate-600">{t('fw.hint')}</p>
        <UploadForm />
      </Card>
      <Card title={t('fw.list')}>
        {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : q.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">
            {q.data.map((r) => (
              <Release key={r.id} r={r} />
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function UploadForm() {
  const upload = useUploadFirmware();
  const [version, setVersion] = useState('');
  const [notes, setNotes] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const fieldError = (name: string) => (upload.error instanceof ApiError ? upload.error.fieldError(name) : undefined);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (file) upload.mutate({ version, notes, file }, { onSuccess: () => { setVersion(''); setNotes(''); setFile(null); } });
  };

  return (
    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2" noValidate>
      <TextField label={t('fw.version')} value={version} onChange={(e) => setVersion(e.target.value.trim())} error={fieldError('version')} required />
      <TextField label={t('fw.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <TextField label={t('fw.file')} type="file" accept=".bin,application/octet-stream" onChange={(e) => setFile(e.target.files?.[0] ?? null)} error={fieldError('file')} required />
      <div className="flex items-end">
        <Button type="submit" disabled={!file || !version} loading={upload.isPending}>{t('fw.uploadBtn')}</Button>
      </div>
      {upload.isError && !fieldError('version') && !fieldError('file') && <div className="sm:col-span-2"><ErrorBanner error={upload.error} /></div>}
    </form>
  );
}

function Release({ r }: { r: FirmwareRelease }) {
  const publish = useFirmwareAction(r.id, 'publish');
  const rollout = useFirmwareAction(r.id, 'rollout');
  const result = rollout.data?.data as RolloutResult | undefined;
  return (
    <li className="space-y-2 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-semibold">
            {r.version}{' '}
            <span className={`ml-1 rounded-full px-2 py-0.5 text-xs ${r.isPublished ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
              {r.isPublished ? t('fw.published') : t('fw.draft')}
            </span>
          </p>
          <p className="break-all font-mono text-xs text-slate-500">sha256 {r.sha256} · {(r.size / 1024).toFixed(0)} KB{r.publishedAt ? ` · ${formatDateTime(r.publishedAt)}` : ''}</p>
          {r.notes && <p className="text-sm text-slate-600">{r.notes}</p>}
        </div>
        <div className="flex gap-2">
          {!r.isPublished && <Button variant="secondary" loading={publish.isPending} onClick={() => publish.mutate()}>{t('fw.publish')}</Button>}
          {r.isPublished && <Button loading={rollout.isPending} onClick={() => window.confirm(t('fw.confirmRollout')) && rollout.mutate()}>{t('fw.rollout')}</Button>}
        </div>
      </div>
      {(publish.isError || rollout.isError) && <ErrorBanner error={publish.error ?? rollout.error} />}
      {result && <SuccessBanner>{t('fw.rolloutResult', { queued: result.queued, busy: result.skippedBusy, current: result.alreadyCurrent })}</SuccessBanner>}
    </li>
  );
}
