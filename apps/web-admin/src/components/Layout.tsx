import { NavLink, Outlet, useNavigate } from 'react-router';
import { useMe, useSignOut } from '../auth/useMe';
import { t } from '../i18n';
import type { MessageKey } from '../i18n/uz';

export interface NavItem {
  to: string;
  label: MessageKey;
  end?: boolean;
}

/** Mobile-first shell: top bar + horizontally scrollable nav (works on phones and iPad). */
export function Layout({ title, nav, notificationsTo, unread = 0 }: { title: string; nav: NavItem[]; notificationsTo?: string; unread?: number }) {
  const me = useMe();
  const signOut = useSignOut();
  const navigate = useNavigate();

  return (
    <div className="min-h-dvh">
      <header className="sticky top-0 z-10 bg-brand-800 text-white shadow">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
          <div className="min-w-0">
            <p className="truncate text-base font-semibold">{title}</p>
            <p className="truncate text-xs text-brand-100">{me.data?.tenant?.name ?? me.data?.user.name}</p>
          </div>
          <div className="flex items-center gap-1">
          {notificationsTo && (
            <NavLink to={notificationsTo} className="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg hover:bg-white/10" aria-label={t('nav.notifications')}>
              <span aria-hidden>🔔</span>
              {unread > 0 && <span className="absolute right-1 top-1 rounded-full bg-red-500 px-1.5 text-[10px] font-bold">{unread > 99 ? '99+' : unread}</span>}
            </NavLink>
          )}
          <button
            type="button"
            className="min-h-11 rounded-lg px-3 text-sm font-medium hover:bg-white/10"
            onClick={async () => {
              await signOut();
              navigate('/login', { replace: true });
            }}
          >
            {t('auth.signOut')}
          </button>
          </div>
        </div>
        <nav className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-2 pb-2" aria-label="main">
          {nav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                `whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium ${isActive ? 'bg-white text-brand-800' : 'text-white/90 hover:bg-white/10'}`
              }
            >
              {t(item.label)}
            </NavLink>
          ))}
        </nav>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-4">
        <Outlet />
      </main>
    </div>
  );
}
