import { createBrowserRouter, RouterProvider } from 'react-router';
import { HomeRedirect, RequireAuth } from './auth/guards';
import { Layout, type NavItem } from './components/Layout';
import { t } from './i18n';
import { ClientHomePage } from './pages/client/ClientHomePage';
import { LoginPage } from './pages/LoginPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { AuditLogPage } from './pages/super/AuditLogPage';
import { ClientCreatePage } from './pages/super/ClientCreatePage';
import { ClientDetailPage } from './pages/super/ClientDetailPage';
import { ClientsPage } from './pages/super/ClientsPage';
import { DashboardPage } from './pages/super/DashboardPage';
import { PaymentsPage } from './pages/super/PaymentsPage';
import { SettingsPage } from './pages/super/SettingsPage';

const superNav: NavItem[] = [
  { to: '/super', label: 'nav.dashboard', end: true },
  { to: '/super/clients', label: 'nav.clients' },
  { to: '/super/payments', label: 'nav.payments' },
  { to: '/super/audit', label: 'nav.audit' },
  { to: '/super/settings', label: 'nav.settings' },
];

const clientNav: NavItem[] = [{ to: '/client', label: 'nav.dashboard', end: true }];

export const routes = [
  { path: '/login', element: <LoginPage /> },
  { path: '/', element: <HomeRedirect /> },
  {
    path: '/super',
    element: (
      <RequireAuth area="super">
        <Layout title={t('app.superTitle')} nav={superNav} />
      </RequireAuth>
    ),
    children: [
      { index: true, element: <DashboardPage /> },
      { path: 'clients', element: <ClientsPage /> },
      { path: 'clients/new', element: <ClientCreatePage /> },
      { path: 'clients/:id', element: <ClientDetailPage /> },
      { path: 'payments', element: <PaymentsPage /> },
      { path: 'audit', element: <AuditLogPage /> },
      { path: 'settings', element: <SettingsPage /> },
    ],
  },
  {
    path: '/client',
    element: (
      <RequireAuth area="client">
        <Layout title={t('app.title')} nav={clientNav} />
      </RequireAuth>
    ),
    children: [{ index: true, element: <ClientHomePage /> }],
  },
  { path: '*', element: <NotFoundPage /> },
];

const router = createBrowserRouter(routes, { basename: '/admin' });

export function App() {
  return <RouterProvider router={router} />;
}
