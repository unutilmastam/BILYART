import { useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';

const control =
  'w-full min-h-11 rounded-xl border border-slate-300 bg-white px-3.5 text-base text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 hover:border-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 disabled:bg-slate-50 disabled:text-slate-500 aria-[invalid=true]:border-red-500 aria-[invalid=true]:focus:ring-red-500/15';

function Wrapper({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-[13px] font-semibold text-slate-700">
        {label}
      </label>
      {children}
      {hint && !error && <p className="text-xs text-slate-500">{hint}</p>}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

type Common = { label: string; error?: string; hint?: string };

export function TextField({ label, error, hint, ...rest }: Common & InputHTMLAttributes<HTMLInputElement>) {
  const id = useId();
  return (
    <Wrapper id={id} label={label} error={error} hint={hint}>
      <input id={id} aria-invalid={!!error} aria-describedby={error ? `${id}-error` : undefined} className={control} {...rest} />
    </Wrapper>
  );
}

export function TextArea({ label, error, hint, ...rest }: Common & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const id = useId();
  return (
    <Wrapper id={id} label={label} error={error} hint={hint}>
      <textarea id={id} aria-invalid={!!error} className={`${control} min-h-24 py-2`} {...rest} />
    </Wrapper>
  );
}

export function SelectField({
  label,
  error,
  hint,
  options,
  ...rest
}: Common & SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string; label: string }[] }) {
  const id = useId();
  return (
    <Wrapper id={id} label={label} error={error} hint={hint}>
      <select id={id} aria-invalid={!!error} className={control} {...rest}>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </Wrapper>
  );
}
