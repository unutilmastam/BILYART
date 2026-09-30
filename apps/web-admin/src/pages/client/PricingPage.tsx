import { useState, type FormEvent } from 'react';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useBranches, useClientMutation, usePlans } from '../../features/client/api';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';
import { formatMoney, parseMoneyInput } from '../../lib/format';
import type { PricingPlan } from '../../types/api';

export function parseDurations(input: string): number[] | null {
  const parts = input.split(/[\s,;]+/).filter(Boolean);
  if (parts.length === 0 || parts.some((p) => !/^\d{1,3}$/.test(p))) return null;
  const nums = [...new Set(parts.map(Number))].sort((a, b) => a - b);
  return nums.every((n) => n >= 1 && n <= 720) ? nums : null;
}

interface PlanBody {
  name: string;
  branchId: string | null;
  pricePerHour: number;
  roundingStep: number;
  allowedDurations: number[];
}

function PlanForm({ plan, onDone }: { plan?: PricingPlan; onDone?: () => void }) {
  const branches = useBranches();
  const m = useClientMutation<PlanBody>(plan ? `/admin/pricing-plans/${plan.id}` : '/admin/pricing-plans', plan ? 'PATCH' : 'POST');
  const [form, setForm] = useState({
    name: plan?.name ?? 'Standart',
    branchId: plan?.branchId ?? '',
    price: plan ? String(plan.pricePerHour) : '',
    step: plan ? String(plan.roundingStep) : '1000',
    durations: plan ? plan.allowedDurations.join(', ') : '30, 60, 90, 120',
  });
  const [localErr, setLocalErr] = useState<{ price?: string; durations?: string }>({});
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const price = parseMoneyInput(form.price);
    const step = parseMoneyInput(form.step);
    const durations = parseDurations(form.durations);
    const errors = { price: price === null ? t('payment.invalidAmount') : undefined, durations: durations === null ? t('plan.invalidDurations') : undefined };
    setLocalErr(errors);
    if (price === null || step === null || durations === null) return;
    m.mutate({ name: form.name, branchId: form.branchId || null, pricePerHour: price, roundingStep: Math.max(1, step), allowedDurations: durations }, { onSuccess: () => onDone?.() });
  };

  return (
    <form onSubmit={submit} className="space-y-3" noValidate>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('plan.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={err('name')} />
        <SelectField label={t('table.branch')} value={form.branchId} onChange={(e) => setForm({ ...form, branchId: e.target.value })}
          options={[{ value: '', label: t('plan.allBranches') }, ...(branches.data ?? []).map((b) => ({ value: b.id, label: b.name }))]} />
        <TextField label={t('plan.pricePerHour')} inputMode="numeric" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} error={localErr.price ?? err('pricePerHour')} />
        <TextField label={t('plan.roundingStep')} inputMode="numeric" value={form.step} onChange={(e) => setForm({ ...form, step: e.target.value })} error={err('roundingStep')} />
        <div className="sm:col-span-2">
          <TextField label={t('plan.durations')} value={form.durations} onChange={(e) => setForm({ ...form, durations: e.target.value })} error={localErr.durations ?? err('allowedDurations')} />
        </div>
      </div>
      {m.isError && <ErrorBanner error={m.error} />}
      <Button type="submit" loading={m.isPending}>{t('common.save')}</Button>
    </form>
  );
}

function PlanCard({ plan }: { plan: PricingPlan }) {
  const [editing, setEditing] = useState(false);
  const toggle = useClientMutation<{ isActive: boolean }>(`/admin/pricing-plans/${plan.id}`, 'PATCH');
  return (
    <Card
      title={<span className={plan.isActive ? '' : 'text-slate-400 line-through'}>{plan.name} — {formatMoney(plan.pricePerHour)}/soat</span>}
      actions={<Button variant="ghost" onClick={() => setEditing((v) => !v)}>{t('common.edit')}</Button>}
    >
      <ul className="flex flex-wrap gap-2 text-sm">
        {plan.quotes.map((q) => <li key={q.minutes} className="rounded-lg bg-slate-100 px-2 py-1">{q.minutes} daq — <b>{formatMoney(q.amount)}</b></li>)}
      </ul>
      {editing && (
        <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
          <PlanForm plan={plan} onDone={() => setEditing(false)} />
          <Button variant="secondary" loading={toggle.isPending} onClick={() => toggle.mutate({ isActive: !plan.isActive })}>
            {plan.isActive ? t('branch.disable') : t('branch.enable')}
          </Button>
        </div>
      )}
    </Card>
  );
}

export function PricingPage() {
  const q = usePlans();
  return (
    <div className="space-y-4">
      <Card title={t('plan.new')}><PlanForm /></Card>
      {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} /> : q.data.length === 0 ? <Card><Empty /></Card> : q.data.map((p) => <PlanCard key={p.id} plan={p} />)}
    </div>
  );
}
