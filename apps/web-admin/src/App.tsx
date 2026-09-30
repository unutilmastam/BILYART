import { createBrowserRouter, RouterProvider } from 'react-router';
import { HomeRedirect, RequireAuth } from './auth/guards';
import { Layout, type NavItem } from './components/Layout';
import { t } from './i18n';
import { useMe } from './auth/useMe';
import { BranchDetailPage } from './pages/client/BranchDetailPage';
import { BranchesPage } from './pages/client/BranchesPage';
import { ClientHomePage } from './pages/client/ClientHomePage';
import { DevicesPage } from './pages/client/DevicesPage';
import { PricingPage } from './pages/client/PricingPage';
import { ReportsPage } from './pages/client/ReportsPage';
import { SessionDetailPage } from './pages/client/SessionDetailPage';
import { SessionsPage } from './pages/client/SessionsPage';
import { StaffPage } from './pages/client/StaffPage';
import { TablesPage } from './pages/client/TablesPage';
import { TelegramPage } from './pages/client/TelegramPage';
import { TenantSettingsPage } from './pages/client/TenantSettingsPage';
import { LoginPage } from './pages/LoginPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { NotificationsPage } from './pages/NotificationsPage';
import { useNotifications } from './features/notifications';
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

/** Client nav items are shown only when the user's role has the permission (the API enforces it anyway). */
const clientNav: (NavItem & { permission?: string })[] = [
  { to: '/client', label: 'nav.dashboard', end: true },
  { to: '/client/sessions', label: 'nav.sessions', permission: 'sessions.view' },
  { to: '/client/reports', label: 'nav.reports', permission: 'reports.view' },
  { to: '/client/devices', label: 'nav.devices', permission: 'tables.view' },
  { to: '/client/branches', label: 'nav.branches' },
  { to: '/client/tables', label: 'nav.tables', permission: 'tables.view' },
  { to: '/client/pricing', label: 'nav.pricing', permission: 'pricing.manage' },
  { to: '/client/staff', label: 'nav.staff', permission: 'users.manage' },
  { to: '/client/telegram', label: 'nav.telegram', permission: 'telegram.manage' },
  { to: '/client/settings', label: 'nav.clientSettings', permission: 'tenant.settings' },
];

function ClientShell() {
  const me = useMe();
  const perms = me.data?.permissions ?? [];
  const { list } = useNotifications('client');
  return <Layout title={t('app.title')} nav={clientNav.filter((i) => !i.permission || perms.includes(i.permission))} notificationsTo="/client/notifications" unread={list.data?.unread ?? 0} />;
}

function SuperShell() {
  const { list } = useNotifications('super');
  return <Layout title={t('app.superTitle')} nav={superNav} notificationsTo="/super/notifications" unread={list.data?.unread ?? 0} />;
}

export const routes = [
  { path: '/login', element: <LoginPage /> },
  { path: '/', element: <HomeRedirect /> },
  {
    path: '/super',
    element: (
      <RequireAuth area="super">
        <SuperShell />
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
      { path: 'notifications', element: <NotificationsPage area="super" /> },
    ],
  },
  {
    path: '/client',
    element: (
      <RequireAuth area="client">
        <ClientShell />
      </RequireAuth>
    ),
    children: [
      { index: true, element: <ClientHomePage /> },
      { path: 'sessions', element: <SessionsPage /> },
      { path: 'sessions/:id', element: <SessionDetailPage /> },
      { path: 'reports', element: <ReportsPage /> },
      { path: 'devices', element: <DevicesPage /> },
      { path: 'branches', element: <BranchesPage /> },
      { path: 'branches/:id', element: <BranchDetailPage /> },
      { path: 'tables', element: <TablesPage /> },
      { path: 'pricing', element: <PricingPage /> },
      { path: 'staff', element: <StaffPage /> },
      { path: 'telegram', element: <TelegramPage /> },
      { path: 'settings', element: <TenantSettingsPage /> },
      { path: 'notifications', element: <NotificationsPage area="client" /> },
    ],
  },
  { path: '*', element: <NotFoundPage /> },
];

const router = createBrowserRouter(routes, { basename: '/admin' });

export function App() {
  return <RouterProvider router={router} />;
}
