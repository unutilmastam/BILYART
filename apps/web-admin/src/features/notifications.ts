import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib/api';

export interface AppNotification {
  id: string;
  type: string;
  severity: 'INFO' | 'WARNING' | 'CRITICAL';
  text: string;
  createdAt: string;
  readAt: string | null;
}

/** area 'client' → /api/admin/notifications, 'super' → /api/super/notifications */
export function useNotifications(area: 'client' | 'super') {
  const base = area === 'super' ? '/super' : '/admin';
  const qc = useQueryClient();
  const key = ['notifications', area];
  const list = useQuery({ queryKey: key, queryFn: () => api<{ unread: number; data: AppNotification[] }>(`${base}/notifications`), refetchInterval: 60_000 });
  const readAll = useMutation({ mutationFn: () => api(`${base}/notifications/read-all`, { method: 'POST' }), onSuccess: () => qc.invalidateQueries({ queryKey: key }) });
  return { list, readAll };
}
