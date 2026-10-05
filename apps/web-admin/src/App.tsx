import {
  Activity,
  BadgeDollarSign,
  Banknote,
  BarChart3,
  Building2,
  Cpu,
  CreditCard,
  LayoutDashboard,
  MessageCircle,
  ReceiptText,
  ScrollText,
  Settings,
  SlidersHorizontal,
  Table2,
  Timer,
  UserRound,
  Users,
  Wallet,
} from 'lucide-react';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { HomeRedirect, RequireAuth } from './auth/guards';
import { Layout, type NavItem } from './components/Layout';
import { t } from './i18n';
import { useMe } from './auth/useMe';
import { BranchDetailPage } from './pages/client/BranchDetailPage';
import { BranchesPage } from './pages/client/BranchesPage';
import { CashPage } from './pages/client/CashPage';
import { ClientHomePage } from './pages/client/ClientHomePage';
import { DevicesPage } from './pages/client/DevicesPage';
import { PricingPage } from './pages/client/PricingPage';
import { ReportsPage } from './pages/client/ReportsPage';
import { SessionDetailPage } from './pages/client/SessionDetailPage';
import { SessionsPage } from './pages/client/SessionsPage';
import { StaffPage } from './pages/client/StaffPage';
import { SubscriptionPage } from './pages/client/SubscriptionPage';
import { TablesPage } from './pages/client/TablesPage';
import { TelegramPage } from './pages/client/TelegramPage';
import { TenantSettingsPage } from './pages/client/TenantSettingsPage';
import { AccountPage } from './pages/account/AccountPage';
import { LoginPage } from './pages/LoginPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { NotificationsPage } from './pages/NotificationsPage';
import { useNotifications } from './features/notifications';
import { AuditLogPage } from './pages/super/AuditLogPage';
import { ClientCreatePage } from './pages/super/ClientCreatePage';
import { ClientDetailPage } from './pages/super/ClientDetailPage';
import { ClientsPage } from './pages/super/ClientsPage';
import { DashboardPage } from './pages/super/DashboardPage';
import { FirmwarePage } from './pages/super/FirmwarePage';
import { HealthPage } from './pages/super/HealthPage';
import { PaymentRequestsPage } from './pages/super/PaymentRequestsPage';
import { PaymentsPage } from './pages/super/PaymentsPage';
import { SettingsPage } from './pages/super/SettingsPage';

const superNav: NavItem[] = [
  { to: '/super', label: 'nav.dashboard', icon: LayoutDashboard, end: true },
  { to: '/super/clients', label: 'nav.clients', icon: Users },
  { to: '/super/payment-requests', label: 'nav.paymentRequests', icon: ReceiptText },
  { to: '/super/payments', label: 'nav.payments', icon: Wallet },
  { to: '/super/audit', label: 'nav.audit', icon: ScrollText },
  { to: '/super/health', label: 'nav.health', icon: Activity },
  { to: '/super/firmware', label: 'nav.firmware', icon: Cpu },
  { to: '/super/settings', label: 'nav.settings', icon: Settings },
  { to: '/super/account', label: 'nav.account', icon: UserRound },
];

/** Client nav items are shown only when the user's role has the permission (the API enforces it anyway). */
const clientNav: (NavItem & { permission?: string })[] = [
  { to: '/client', label: 'nav.dashboard', icon: LayoutDashboard, end: true },
  { to: '/client/sessions', label: 'nav.sessions', icon: Timer, permission: 'sessions.view' },
  { to: '/client/reports', label: 'nav.reports', icon: BarChart3, permission: 'reports.view' },
  { to: '/client/cash', label: 'nav.cash', icon: Banknote, permission: 'reports.view' },
  { to: '/client/devices', label: 'nav.devices', icon: Cpu, permission: 'tables.view' },
  { to: '/client/branches', label: 'nav.branches', icon: Building2 },
  { to: '/client/tables', label: 'nav.tables', icon: Table2, permission: 'tables.view' },
  { to: '/client/pricing', label: 'nav.pricing', icon: BadgeDollarSign, permission: 'pricing.manage' },
  { to: '/client/staff', label: 'nav.staff', icon: Users, permission: 'users.manage' },
  { to: '/client/telegram', label: 'nav.telegram', icon: MessageCircle, permission: 'telegram.manage' },
  { to: '/client/settings', label: 'nav.clientSettings', icon: SlidersHorizontal, permission: 'tenant.settings' },
  { to: '/client/subscription', label: 'nav.subscription', icon: CreditCard, permission: 'billing.manage' },
  { to: '/client/account', label: 'nav.account', icon: UserRound },
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
      { path: 'payment-requests', element: <PaymentRequestsPage /> },
      { path: 'payments', element: <PaymentsPage /> },
      { path: 'audit', element: <AuditLogPage /> },
      { path: 'settings', element: <SettingsPage /> },
      { path: 'health', element: <HealthPage /> },
      { path: 'firmware', element: <FirmwarePage /> },
      { path: 'notifications', element: <NotificationsPage area="super" /> },
      { path: 'account', element: <AccountPage /> },
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
      { path: 'cash', element: <CashPage /> },
      { path: 'devices', element: <DevicesPage /> },
      { path: 'branches', element: <BranchesPage /> },
      { path: 'branches/:id', element: <BranchDetailPage /> },
      { path: 'tables', element: <TablesPage /> },
      { path: 'pricing', element: <PricingPage /> },
      { path: 'staff', element: <StaffPage /> },
      { path: 'telegram', element: <TelegramPage /> },
      { path: 'settings', element: <TenantSettingsPage /> },
      { path: 'subscription', element: <SubscriptionPage /> },
      { path: 'notifications', element: <NotificationsPage area="client" /> },
      { path: 'account', element: <AccountPage /> },
    ],
  },
  { path: '*', element: <NotFoundPage /> },
];

const router = createBrowserRouter(routes, { basename: '/admin' });

export function App() {
  return <RouterProvider router={router} />;
}
