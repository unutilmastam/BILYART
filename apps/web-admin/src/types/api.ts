export type Role = 'SUPER_ADMIN' | 'CLIENT_OWNER' | 'CLIENT_MANAGER' | 'CLIENT_OPERATOR';
export type SubscriptionStatus = 'ACTIVE' | 'EXPIRING_SOON' | 'EXPIRED' | 'SUSPENDED' | 'DEACTIVATED';
export type PaymentMethod = 'CASH' | 'BANK_TRANSFER' | 'CARD_TRANSFER' | 'OTHER';
export const PAYMENT_METHODS: PaymentMethod[] = ['CASH', 'BANK_TRANSFER', 'CARD_TRANSFER', 'OTHER'];
export const SUBSCRIPTION_STATUSES: SubscriptionStatus[] = ['ACTIVE', 'EXPIRING_SOON', 'EXPIRED', 'SUSPENDED', 'DEACTIVATED'];

export interface User {
  id: string;
  name: string;
  login: string;
  role: Role;
  isActive: boolean;
  lastLoginAt: string | null;
  twoFactorEnabled: boolean;
}

export interface SubscriptionInfo {
  status: SubscriptionStatus;
  expiresAt: string | null;
  daysLeft: number;
}

export interface Me {
  user: User;
  permissions: string[];
  tenant: { id: string; name: string; subscription: SubscriptionInfo } | null;
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; perPage: number; total: number; sum?: number };
}

export interface Limits {
  branchLimit: number;
  tableLimit: number | null;
  deviceLimit: number | null;
  userLimit: number | null;
}

export interface Tenant {
  id: string;
  name: string;
  contactName: string | null;
  contactPhone: string | null;
  timezone: string;
  statusFlag: 'ACTIVE' | 'SUSPENDED' | 'DEACTIVATED';
  subscription: SubscriptionInfo;
  limits: Limits;
  usage?: { branches: number; tables: number; devices: number; users: number };
  createdAt: string | null;
}

export interface Payment {
  id: string;
  tenant?: { id: string; name: string };
  amount: number;
  currency: 'UZS';
  method: PaymentMethod;
  note: string | null;
  paidAt: string;
  createdAt: string | null;
}

export interface AuditEntry {
  id: number;
  action: string;
  actorType: 'USER' | 'DEVICE' | 'TABLET' | 'SYSTEM';
  actorName: string | null;
  entityType: string | null;
  entityId: string | null;
  metadata: Record<string, unknown> | null;
  ip: string | null;
  tenant: string | null;
  createdAt: string;
}

export interface SubscriptionHistory {
  tenant: Tenant;
  periods: { id: string; startsAt: string; expiresAt: string; days: number; source: 'PAYMENT' | 'MANUAL_ADJUST'; reason: string | null }[];
  payments: Payment[];
  events: { type: string; oldValue: unknown; newValue: unknown; createdAt: string }[];
}

export interface SuperDashboard {
  tenants: { total: number; active: number; expiringSoon: number; expired: number; suspended: number; deactivated: number };
  revenue: { currency: 'UZS'; thisMonth: number; total: number };
  devices: { paired: number; online: number };
  recentAudit: AuditEntry[];
}

export interface PlatformSettings {
  supportContact: string;
  paymentInstructions: string;
  defaultBranchLimit: number;
  reminderDays: number[];
}

export interface ClientSubscription extends SubscriptionInfo {
  limits: Limits;
  supportContact: string;
  paymentInstructions: string;
}

export interface Branch {
  id: string;
  name: string;
  address: string | null;
  phone: string | null;
  timezone: string;
  isActive: boolean;
  reportTime: string;
}

export interface WorkingDay {
  weekday: number;
  isClosed: boolean;
  opensAt: string | null;
  closesAt: string | null;
}

export interface ClosedDay {
  id: string;
  date: string;
  reason: string | null;
}

export interface PricingPlan {
  id: string;
  name: string;
  type: 'HOURLY';
  branchId: string | null;
  pricePerHour: number;
  roundingStep: number;
  allowedDurations: number[];
  quotes: { minutes: number; amount: number }[];
  isActive: boolean;
}

export interface Table {
  id: string;
  branchId: string;
  number: number;
  name: string;
  isActive: boolean;
  pricingPlan: { id: string; name: string; pricePerHour: number } | null;
  device: { id: string; code: string; online: boolean; lastSeenAt: string | null } | null;
}

export interface StaffUser extends User {
  branchIds: string[];
}

export interface TenantSettings {
  privacyNotice: string;
  photoRetentionDays: number;
  warningText: string;
  warnBeforeMinutes: number;
  locale: 'uz' | 'ru';
  operatorsCanViewPhotos: boolean;
}

export type SessionStatusValue = 'RESERVED' | 'STARTING' | 'ACTIVE' | 'COMPLETING' | 'COMPLETED' | 'CANCELLED' | 'FAILED';
export type PaymentStatusValue = 'UNPAID' | 'PAID' | 'WAIVED';
export type TableStatusValue = 'AVAILABLE' | 'RESERVED' | 'STARTING' | 'BUSY' | 'WARNING' | 'DISABLED' | 'DEVICE_OFFLINE' | 'CLOSED';

export interface GameSession {
  id: string;
  status: SessionStatusValue;
  effectiveStatus: SessionStatusValue;
  branch?: { id: string; name: string };
  table?: { id: string; number: number; name: string };
  device?: { code: string; online: boolean } | null;
  durationMinutes: number;
  startAt: string | null;
  endAt: string | null;
  endedAt: string | null;
  endedEarly: boolean;
  pricePerHour: number;
  amount: number;
  paymentStatus: PaymentStatusValue;
  paymentMarkedAt: string | null;
  failureReason: string | null;
  photo?: { id: string; createdAt: string } | null;
  events?: { from: string | null; to: string; actorType: string; reason: string | null; at: string }[];
  createdAt: string | null;
}

export interface ClientDashboard {
  serverTime: string;
  totals: { playing: number; available: number; sessionsToday: number; amountToday: number; minutesToday: number; unpaidToday: number; devicesOnline: number; devicesOffline: number };
  branches: {
    branch: { id: string; name: string };
    playing: number;
    available: number;
    sessionsToday: number;
    amountToday: number;
    minutesToday: number;
    unpaidToday: number;
    devicesOnline: number;
    devicesOffline: number;
    tables: { id: string; number: number; name: string; status: TableStatusValue; endAt: string | null }[];
  }[];
}

export interface ReportFigures {
  sessions: number;
  minutes: number;
  amount: number;
  unpaidCount: number;
  unpaidAmount: number;
}

export interface Report {
  from: string;
  to: string;
  totals: ReportFigures;
  branches: (ReportFigures & {
    branch: { id: string; name: string; timezone: string };
    utilizationPercent: number;
    tables: { id: string; number: number; name: string; sessions: number; minutes: number; amount: number; utilizationPercent: number }[];
  })[];
}

export interface DeviceInfo {
  id: string;
  code: string;
  status: 'PAIRED' | 'UNPAIRED' | 'REVOKED';
  online: boolean;
  lastSeenAt: string | null;
  firmwareVersion: string | null;
  state: 'ON' | 'OFF' | 'WARNING' | null;
  rssi: number | null;
  branch?: { id: string; name: string } | null;
  table?: { id: string; number: number; name: string } | null;
  pairedAt: string | null;
}

export interface TabletInfo {
  id: string;
  code: string;
  name: string | null;
  status: 'PAIRED' | 'UNPAIRED' | 'REVOKED';
  online: boolean;
  lastSeenAt: string | null;
  appVersion: string | null;
  deviceModel: string | null;
  branch?: { id: string; name: string } | null;
  pairedAt: string | null;
}
