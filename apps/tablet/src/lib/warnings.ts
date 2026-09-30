import type { TabletTable } from '@bilyart/protocol';

/**
 * Decides locally when a table's "5 minutes left" warning is due (spec §12).
 * Runs from the stored endAt + server-time offset, so it works while the
 * network is down. Each session is announced once.
 */
export class WarningScheduler {
  private announced = new Set<string>();

  constructor(private readonly warnBeforeSec: number) {}

  /** Tables whose warning is due now and was not announced yet. */
  due(tables: TabletTable[], serverNowMs: number): TabletTable[] {
    const out: TabletTable[] = [];
    for (const t of tables) {
      const s = t.session;
      if (!s?.endAt || s.status !== 'ACTIVE' || this.announced.has(s.id)) continue;
      const end = Date.parse(s.endAt);
      if (serverNowMs >= end - this.warnBeforeSec * 1000 && serverNowMs < end) {
        this.announced.add(s.id);
        out.push(t);
      }
    }
    return out;
  }
}

export function warningText(template: string, table: Pick<TabletTable, 'number' | 'name'>): string {
  return template.replaceAll('{table}', String(table.number)).replaceAll('{name}', table.name);
}

/**
 * Speaks the warning with the device's text-to-speech. Many Android tablets
 * have no Uzbek voice: the message is then read by the closest available
 * voice and always preceded by a chime, so the hall hears it either way.
 */
export async function announce(text: string): Promise<void> {
  await chime();
  const synth = typeof window !== 'undefined' ? window.speechSynthesis : undefined;
  if (!synth) return;
  const u = new SpeechSynthesisUtterance(text);
  const voices = synth.getVoices();
  const voice = voices.find((v) => v.lang.toLowerCase().startsWith('uz')) ?? voices.find((v) => v.lang.toLowerCase().startsWith('tr')) ?? voices.find((v) => v.lang.toLowerCase().startsWith('ru'));
  if (voice) u.voice = voice;
  u.lang = voice?.lang ?? 'uz-UZ';
  u.rate = 0.95;
  synth.cancel();
  synth.speak(u);
}

async function chime(): Promise<void> {
  const Ctx = typeof window !== 'undefined' ? (window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext) : undefined;
  if (!Ctx) return;
  const ctx = new Ctx();
  const at = ctx.currentTime;
  [0, 0.35, 0.7].forEach((offset, i) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.frequency.value = i === 2 ? 1046 : 880;
    gain.gain.setValueAtTime(0.0001, at + offset);
    gain.gain.exponentialRampToValueAtTime(0.4, at + offset + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + offset + 0.3);
    osc.connect(gain).connect(ctx.destination);
    osc.start(at + offset);
    osc.stop(at + offset + 0.32);
  });
  await new Promise((r) => setTimeout(r, 1100));
  await ctx.close();
}
