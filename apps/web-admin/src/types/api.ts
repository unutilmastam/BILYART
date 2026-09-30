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
