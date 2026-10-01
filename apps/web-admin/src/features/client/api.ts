import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { Branch, ClosedDay, DeviceInfo, PricingPlan, StaffUser, Table, TenantSettings, WorkingDay } from '../../types/api';

type Data<T> = { data: T };

export const clientKeys = {
  all: ['client'] as const,
  branches: ['client', 'branches'] as const,
  branch: (id: string) => ['client', 'branch', id] as const,
  hours: (id: string) => ['client', 'branch', id, 'hours'] as const,
  closed: (id: string) => ['client', 'branch', id, 'closed'] as const,
  tables: (branchId?: string) => ['client', 'tables', branchId ?? 'all'] as const,
  plans: ['client', 'plans'] as const,
  users: ['client', 'users'] as const,
  settings: ['client', 'settings'] as const,
  devices: ['client', 'devices'] as const,
};

export const useBranches = () => useQuery({ queryKey: clientKeys.branches, queryFn: async () => (await api<Data<Branch[]>>('/admin/branches')).data });
export const useBranch = (id: string) => useQuery({ queryKey: clientKeys.branch(id), queryFn: async () => (await api<Data<Branch>>(`/admin/branches/${id}`)).data });
export const useWorkingHours = (id: string) => useQuery({ queryKey: clientKeys.hours(id), queryFn: async () => (await api<Data<WorkingDay[]>>(`/admin/branches/${id}/working-hours`)).data });
export const useClosedDays = (id: string) => useQuery({ queryKey: clientKeys.closed(id), queryFn: async () => (await api<Data<ClosedDay[]>>(`/admin/branches/${id}/closed-days`)).data });
export const useTables = (branchId?: string) =>
  useQuery({ queryKey: clientKeys.tables(branchId), queryFn: async () => (await api<Data<Table[]>>('/admin/tables', { query: { branchId } })).data });
export const usePlans = (enabled = true) => useQuery({ queryKey: clientKeys.plans, enabled, queryFn: async () => (await api<Data<PricingPlan[]>>('/admin/pricing-plans')).data });
export const useStaff = () => useQuery({ queryKey: clientKeys.users, queryFn: async () => (await api<Data<StaffUser[]>>('/admin/users')).data });
export const useDevices = (enabled = true) =>
  useQuery({ queryKey: clientKeys.devices, enabled, queryFn: async () => (await api<Data<DeviceInfo[]>>('/admin/devices')).data, refetchInterval: 10_000 });
export const useTenantSettings = () => useQuery({ queryKey: clientKeys.settings, queryFn: () => api<TenantSettings>('/admin/settings') });

/** Any client mutation on a fixed path; refreshes every client view on success. */
export function useClientMutation<TBody = void, TResult = unknown>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: TBody) => api<TResult>(path, { method, body: method === 'DELETE' || body === undefined ? undefined : body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: clientKeys.all }),
  });
}
