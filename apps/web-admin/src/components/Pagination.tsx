import { t } from '../i18n';
import { Button } from './Button';

export function Pagination({ page, perPage, total, onPage }: { page: number; perPage: number; total: number; onPage: (p: number) => void }) {
  const last = Math.max(1, Math.ceil(total / perPage));
  if (total <= perPage) return null;
  return (
    <nav className="mt-3 flex items-center justify-between gap-2 text-sm text-slate-600" aria-label="pagination">
      <Button variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        {t('common.prev')}
      </Button>
      <span>{t('common.page', { page, total })}</span>
      <Button variant="secondary" disabled={page >= last} onClick={() => onPage(page + 1)}>
        {t('common.next')}
      </Button>
    </nav>
  );
}
