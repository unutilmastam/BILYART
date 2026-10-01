import type { LucideIcon } from 'lucide-react';
import { Bell, LogOut, Menu, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import { useMe, useSignOut } from '../auth/useMe';
import { t, tDynamic } from '../i18n';
import type { MessageKey } from '../i18n/uz';
import { Logo } from './Logo';

export interface NavItem {
  to: string;
  label: MessageKey;
  icon: LucideIcon;
  end?: boolean;
}

function NavList({ nav, onNavigate }: { nav: NavItem[]; onNavigate?: () => void }) {
  return (
    <nav className="flex flex-col gap-0.5" aria-label="main">
      {nav.map(({ to, label, icon: Icon, end }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          onClick={onNavigate}
          className={({ isActive }) =>
            `group relative flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium transition ${
              isActive ? 'bg-white/12 text-white shadow-inner ring-1 ring-white/10' : 'text-brand-100/75 hover:bg-white/6 hover:text-white'
            }`
          }
        >
          {({ isActive }) => (
            <>
              {isActive && <span className="absolute inset-y-2 left-0 w-1 rounded-r-full bg-accent-400" aria-hidden />}
              <Icon className={`size-[18px] shrink-0 ${isActive ? 'text-accent-300' : 'text-brand-200/70 group-hover:text-brand-100'}`} aria-hidden />
              <span className="truncate">{t(label)}</span>
            </>
          )}
        </NavLink>
      ))}
    </nav>
  );
}

function UserCard({ onSignOut }: { onSignOut: () => void }) {
  const me = useMe();
  const user = me.data?.user;
  const initials = (user?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  return (
    <div className="flex items-center gap-3 rounded-2xl bg-white/6 p-3 ring-1 ring-white/10">
      <span className="grid size-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-accent-300 to-accent-500 text-sm font-bold text-brand-950">{initials}</span>
      <div className="min-w-0 flex-1 leading-tight">
        <p className="truncate text-sm font-semibold text-white">{user?.name}</p>
        <p className="truncate text-xs text-brand-200/70">{user ? tDynamic('role', user.role) : ''}</p>
      </div>
      <button type="button" onClick={onSignOut} className="grid size-9 place-items-center rounded-lg text-brand-100/80 hover:bg-white/10 hover:text-white" aria-label={t('auth.signOut')} title={t('auth.signOut')}>
        <LogOut className="size-[18px]" aria-hidden />
      </button>
    </div>
  );
}

/**
 * App shell: dark felt sidebar on desktop/iPad landscape, top bar + slide-in menu on phones.
 * Navigation items are role-filtered by the caller; the API enforces permissions anyway.
 */
export function Layout({ title, nav, notificationsTo, unread = 0 }: { title: string; nav: NavItem[]; notificationsTo?: string; unread?: number }) {
  const me = useMe();
  const signOut = useSignOut();
  const navigate = useNavigate();
  const location = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);
  const subtitle = me.data?.tenant?.name ?? title;

  useEffect(() => setMenuOpen(false), [location.pathname]);

  const current = [...nav].sort((a, b) => b.to.length - a.to.length).find((i) => (i.end ? location.pathname === i.to : location.pathname.startsWith(i.to)));
  const pageTitle = current ? t(current.label) : title;

  const doSignOut = async () => {
    await signOut();
    navigate('/login', { replace: true });
  };

  return (
    <div className="min-h-dvh lg:pl-72">
      {/* Desktop / iPad landscape sidebar */}
      <aside className="felt fixed inset-y-0 left-0 z-20 hidden w-72 flex-col gap-6 p-5 lg:flex">
        <Logo subtitle={subtitle} />
        <div className="-mx-1 flex-1 overflow-y-auto px-1">
          <NavList nav={nav} />
        </div>
        <UserCard onSignOut={doSignOut} />
      </aside>

      {/* One top bar: felt with logo + menu on phones, light with the page title on desktop. */}
      <header className="felt sticky top-0 z-10 shadow-pop lg:border-b lg:border-slate-200/70 lg:bg-canvas/85 lg:bg-none lg:shadow-none lg:backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8 lg:py-4">
          <div className="min-w-0 lg:hidden">
            <Logo subtitle={subtitle} />
          </div>
          <h1 className="hidden truncate text-2xl font-bold tracking-tight text-slate-900 lg:block">{pageTitle}</h1>
          <div className="flex items-center gap-2">
            {notificationsTo && (
              <NavLink
                to={notificationsTo}
                className="relative grid size-11 place-items-center rounded-xl text-white transition hover:bg-white/10 lg:bg-white lg:text-slate-600 lg:ring-1 lg:ring-slate-200 lg:hover:bg-slate-50 lg:hover:text-slate-900"
                aria-label={t('nav.notifications')}
              >
                <Bell className="size-5" aria-hidden />
                {unread > 0 && (
                  <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-500 px-1.5 text-center text-[10px] font-bold leading-5 text-white ring-2 ring-brand-950 lg:ring-white">
                    {unread > 99 ? '99+' : unread}
                  </span>
                )}
              </NavLink>
            )}
            <button type="button" onClick={() => setMenuOpen(true)} className="grid size-11 place-items-center rounded-xl text-white hover:bg-white/10 lg:hidden" aria-label={t('nav.menu')} aria-expanded={menuOpen}>
              <Menu className="size-6" aria-hidden />
            </button>
          </div>
        </div>
      </header>

      {menuOpen && (
        <div className="fixed inset-0 z-30 lg:hidden" role="dialog" aria-modal="true" aria-label={t('nav.menu')}>
          <button type="button" className="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" onClick={() => setMenuOpen(false)} aria-label={t('common.close')} />
          <div className="felt absolute inset-y-0 right-0 flex w-80 max-w-[85vw] flex-col gap-5 p-5 shadow-pop">
            <div className="flex items-center justify-between">
              <Logo subtitle={subtitle} />
              <button type="button" onClick={() => setMenuOpen(false)} className="grid size-10 place-items-center rounded-xl text-white hover:bg-white/10" aria-label={t('common.close')}>
                <X className="size-5" aria-hidden />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto">
              <NavList nav={nav} onNavigate={() => setMenuOpen(false)} />
            </div>
            <UserCard onSignOut={doSignOut} />
          </div>
        </div>
      )}

      <div className="mx-auto max-w-6xl px-4 pb-12 sm:px-6 lg:px-8">
        <p className="pb-1 pt-5 text-xl font-bold tracking-tight text-slate-900 lg:hidden">{pageTitle}</p>
        <main className="space-y-4 pt-2 lg:pt-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
