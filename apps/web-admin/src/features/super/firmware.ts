import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';

export interface FirmwareRelease {
  id: string;
  version: string;
  sha256: string;
  size: number;
  notes: string | null;
  isPublished: boolean;
  publishedAt: string | null;
}

export interface RolloutResult {
  queued: number;
  skippedBusy: number;
  alreadyCurrent: number;
}

const KEY = ['super', 'firmware'] as const;

export const useFirmware = () => useQuery({ queryKey: KEY, queryFn: async () => (await api<{ data: FirmwareRelease[] }>('/super/firmware')).data });

export function useUploadFirmware() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { version: string; notes: string; file: File }) => {
      const form = new FormData();
      form.append('version', input.version);
      if (input.notes) form.append('notes', input.notes);
      form.append('file', input.file);
      return api<{ data: FirmwareRelease }>('/super/firmware', { method: 'POST', form });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useFirmwareAction(id: string, action: 'publish' | 'rollout') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api<{ data: RolloutResult | FirmwareRelease }>(`/super/firmware/${id}/${action}`, { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
