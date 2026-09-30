import qrcode from 'qrcode-generator';
import { useMemo } from 'react';

/** Renders text as a QR image (data: URL, allowed by the CSP). Generated locally — the secret never leaves the page. */
export function QrCode({ text, label }: { text: string; label: string }) {
  const src = useMemo(() => {
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createDataURL(5, 2);
  }, [text]);
  return <img src={src} alt={label} className="size-48 rounded-lg ring-1 ring-slate-200" />;
}
