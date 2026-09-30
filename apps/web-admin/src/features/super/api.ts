import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type {
  AuditEntry,
  Limits,
  Paginated,
  Payment,
  PaymentMethod,
  PlatformSettings,
  SubscriptionHistory,
  SubscriptionStatus,
  SuperDashboard,
  Tenant,
} from '../../types/api';

export const superKeys = {
  dashboard: ['super', 'dashboard'] as const,
  tenants: (params: object) => ['super', 'tenants', params] as const,
  tenant: (id: string) => ['super', 'tenant', id] as const,
  history: (id: string) => ['super', 'tenant', id, 'history'] as const,
  payments: (params: object) => ['super', 'payments', params] as const,
  audit: (params: object) => ['super', 'audit', params] as const,
  settings: ['super', 'settings'] as const,
};

export const useDashboard = () => useQuery({ queryKey: superKeys.dashboard, queryFn: () => api<SuperDashboard>('/super/dashboard') });

export const useTenants = (params: { page: number; status?: SubscriptionStatus | ''; q?: string }) =>
  useQuery({
    queryKey: superKeys.tenants(params),
    queryFn: () => api<Paginated<Tenant>>('/super/tenants', { query: params }),
    placeholderData: (prev) => prev,
  });

export const useTenant = (id: string) =>
  useQuery({ queryKey: superKeys.tenant(id), queryFn: async () => (await api<{ data: Tenant }>(`/super/tenants/${id}`)).data });

export const useHistory = (id: string) =>
  useQuery({ queryKey: superKeys.history(id), queryFn: () => api<SubscriptionHistory>(`/super/tenants/${id}/subscription`) });

export const usePayments = (params: { page: number }) =>
  useQuery({ queryKey: superKeys.payments(params), queryFn: () => api<Paginated<Payment>>('/super/payments', { query: params }), placeholderData: (p) => p });

export const useAudit = (params: { page: number; action?: string }) =>
  useQuery({ queryKey: superKeys.audit(params), queryFn: () => api<Paginated<AuditEntry>>('/super/audit-logs', { query: params }), placeholderData: (p) => p });

export const useSettings = () => useQuery({ queryKey: superKeys.settings, queryFn: () => api<PlatformSettings>('/super/settings') });

export interface CreateTenantInput {
  name: string;
  contactName?: string;
  contactPhone?: string;
  branchLimit: number;
  owner: { name: string; login: string; password: string };
  payment?: { amount: number; method: PaymentMethod; days: number; note?: string } | null;
}

export function useCreateTenant() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateTenantInput) => api<{ data: Tenant }>('/super/tenants', { method: 'POST', body: input }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['super'] }),
  });
}

/** Generic tenant action (suspend, payments, extend, …) that refreshes every super view afterwards. */
export function useTenantAction<TBody>(id: string, path: string, method: 'POST' | 'PUT' = 'POST') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: TBody) => api<{ data: Tenant }>(`/super/tenants/${id}${path}`, { method, body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['super'] }),
  });
}

export type LimitsInput = Limits;

export function useResetOwnerPassword(id: string) {
  return useMutation({
    mutationFn: () => api<{ login: string; temporaryPassword: string }>(`/super/tenants/${id}/owner/reset-password`, { method: 'POST' }),
  });
}

export function useSaveSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Partial<PlatformSettings>) => api<PlatformSettings>('/super/settings', { method: 'PUT', body }),
    onSuccess: (data) => qc.setQueryData(superKeys.settings, data),
  });
}
