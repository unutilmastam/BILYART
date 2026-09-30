import { Link } from 'react-router';
import { t } from '../i18n';

export function NotFoundPage() {
  return (
    <div className="p-8 text-center">
      <p className="text-lg font-semibold">{t('error.notFound')}</p>
      <Link to="/" className="text-brand-700">{t('common.back')}</Link>
    </div>
  );
}
