import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { ClientSubscription, PaymentMethod, PaymentRequest, PaymentRequestStatus } from '../../types/api';

/** Shared with ClientHomePage so a new request refreshes the subscription card too. */
export const subscriptionKey = ['client', 'subscription'] as const;

export const useClientSubscription = () => useQuery({ queryKey: subscriptionKey, queryFn: () => api<ClientSubscription>('/admin/subscription') });

export const useMyPaymentRequests = () =>
  useQuery({ queryKey: ['client', 'payment-requests'], queryFn: async () => (await api<{ data: PaymentRequest[] }>('/admin/payment-requests')).data });

export function useSendPaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { months: number; receipt: File; note?: string }) => {
      const form = new FormData();
      form.append('months', String(input.months));
      form.append('receipt', input.receipt);
      if (input.note) form.append('note', input.note);
      return api<{ data: PaymentRequest }>('/admin/payment-requests', { method: 'POST', form });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['client'] }),
  });
}

export function useCancelPaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => api<{ data: PaymentRequest }>(`/admin/payment-requests/${id}/cancel`, { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['client'] }),
  });
}

export const useSuperPaymentRequests = (status: PaymentRequestStatus | '') =>
  useQuery({
    queryKey: ['super', 'payment-requests', status],
    queryFn: async () => (await api<{ data: PaymentRequest[] }>('/super/payment-requests', { query: { status } })).data,
    placeholderData: (p) => p,
  });

export function useReviewPaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string } & ({ action: 'approve'; amount?: number; method: PaymentMethod } | { action: 'reject'; reason: string })) => {
      const { id, action, ...body } = input;
      return api<{ data: PaymentRequest }>(`/super/payment-requests/${id}/${action}`, { method: 'POST', body });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['super'] }),
  });
}

export const receiptUrl = (area: 'admin' | 'super', id: string) => `/api/${area}/payment-requests/${id}/receipt`;
