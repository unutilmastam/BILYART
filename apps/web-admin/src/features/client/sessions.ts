import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { ClientDashboard, GameSession, Paginated, PaymentStatusValue, Report } from '../../types/api';

export const useClientDashboard = () =>
  useQuery({ queryKey: ['client', 'dashboard'], queryFn: () => api<ClientDashboard>('/admin/dashboard'), refetchInterval: 10_000 });

export const useSessions = (params: { page: number; status?: string; payment?: string; branchId?: string }) =>
  useQuery({ queryKey: ['client', 'sessions', params], queryFn: () => api<Paginated<GameSession>>('/admin/sessions', { query: params }), placeholderData: (p) => p, refetchInterval: 15_000 });

export const useSession = (id: string) =>
  useQuery({ queryKey: ['client', 'session', id], queryFn: async () => (await api<{ data: GameSession }>(`/admin/sessions/${id}`)).data });

export function useStopSession(id: string) {
  const qc = useQueryClient();
  return useMutation({ mutationFn: () => api(`/admin/sessions/${id}/stop`, { method: 'POST' }), onSuccess: () => qc.invalidateQueries({ queryKey: ['client'] }) });
}

export function useMarkPayment(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (status: PaymentStatusValue) => api(`/admin/sessions/${id}/payment`, { method: 'POST', body: { status } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['client'] }),
  });
}

export const useReport = (kind: 'daily' | 'monthly', value: string, branchId?: string) =>
  useQuery({
    queryKey: ['client', 'report', kind, value, branchId],
    enabled: value !== '',
    queryFn: () => api<Report>(`/admin/reports/${kind}`, { query: kind === 'daily' ? { date: value, branchId } : { month: value, branchId } }),
  });
