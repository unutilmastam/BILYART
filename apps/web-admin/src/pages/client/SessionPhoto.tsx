import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '../../components/Button';
import { ErrorBanner } from '../../components/Feedback';
import { t } from '../../i18n';
import { api } from '../../lib/api';

/**
 * The customer photo is personal data (spec §59): it is loaded only when staff
 * press the button (each view is audited server-side) and never cached.
 */
export function SessionPhoto({ photoId, canDelete }: { photoId: string | null; canDelete: boolean }) {
  const [show, setShow] = useState(false);
  const [failed, setFailed] = useState(false);
  const qc = useQueryClient();
  const del = useMutation({
    mutationFn: () => api(`/admin/photos/${photoId}`, { method: 'DELETE' }),
    onSuccess: () => { setShow(false); qc.invalidateQueries({ queryKey: ['client'] }); },
  });

  if (!photoId) return <p className="text-sm text-slate-500">{t('sess.noPhoto')}</p>;

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">{t('sess.photoNotice')}</p>
      {!show ? (
        <Button variant="secondary" onClick={() => { setFailed(false); setShow(true); }}>{t('sess.showPhoto')}</Button>
      ) : failed ? (
        <ErrorBanner error={new Error('photo')} />
      ) : (
        <img src={`/api/admin/photos/${photoId}`} alt={t('sess.photo')} className="max-h-96 rounded-lg ring-1 ring-slate-200" onError={() => setFailed(true)} referrerPolicy="no-referrer" />
      )}
      {canDelete && (
        <Button variant="danger" loading={del.isPending} onClick={() => window.confirm(t('sess.confirmDeletePhoto')) && del.mutate()}>{t('sess.deletePhoto')}</Button>
      )}
      {del.isError && <ErrorBanner error={del.error} />}
    </div>
  );
}
