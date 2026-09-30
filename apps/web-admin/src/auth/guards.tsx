import type { ReactNode } from 'react';
import { Navigate } from 'react-router';
import { ErrorBanner, Spinner } from '../components/Feedback';
import { useMe } from './useMe';

/** UI convenience only — every endpoint is authorized on the server. */
export function RequireAuth({ area, children }: { area: 'super' | 'client'; children: ReactNode }) {
  const me = useMe();
  if (me.isPending) return <Spinner />;
  if (me.isError) return <div className="p-4"><ErrorBanner error={me.error} onRetry={() => me.refetch()} /></div>;
  if (!me.data) return <Navigate to="/login" replace />;

  const isSuper = me.data.user.role === 'SUPER_ADMIN';
  if (area === 'super' && !isSuper) return <Navigate to="/" replace />;
  if (area === 'client' && isSuper) return <Navigate to="/super" replace />;
  return <>{children}</>;
}

export function HomeRedirect() {
  const me = useMe();
  if (me.isPending) return <Spinner />;
  if (!me.data) return <Navigate to="/login" replace />;
  return <Navigate to={me.data.user.role === 'SUPER_ADMIN' ? '/super' : '/client'} replace />;
}
