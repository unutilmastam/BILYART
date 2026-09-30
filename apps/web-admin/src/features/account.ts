import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ME_KEY } from '../auth/useMe';
import { api } from '../lib/api';

/** Own-account actions (/api/me/*). Every change refreshes the profile. */
export function useChangePassword() {
  return useMutation({
    mutationFn: (body: { currentPassword: string; password: string }) => api<void>('/me/password', { method: 'PUT', body }),
  });
}

export function useTwoFactorSetup() {
  return useMutation({
    mutationFn: (password: string) => api<{ secret: string; uri: string }>('/me/2fa/setup', { method: 'POST', body: { password } }),
  });
}

export function useTwoFactorConfirm() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (code: string) => api<{ recoveryCodes: string[] }>('/me/2fa/confirm', { method: 'POST', body: { code } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  });
}

export function useTwoFactorDisable() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { password: string; code: string }) => api<void>('/me/2fa/disable', { method: 'POST', body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  });
}
