import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../lib/api';
import type { Me } from '../types/api';

export const ME_KEY = ['me'] as const;

/** Current user, or null when not logged in. Other errors propagate to the error UI. */
export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async (): Promise<Me | null> => {
      try {
        return await api<Me>('/me');
      } catch (e) {
        if (e instanceof ApiError && (e.status === 401 || e.code === 'ACCOUNT_DISABLED')) return null;
        throw e;
      }
    },
    staleTime: 60_000,
    retry: false,
  });
}

export function useSignOut() {
  const qc = useQueryClient();
  return async () => {
    try {
      await api('/auth/logout', { method: 'POST' });
    } finally {
      qc.clear();
      qc.setQueryData(ME_KEY, null);
    }
  };
}
