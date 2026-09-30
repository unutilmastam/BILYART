/**
 * Server-time offset (spec §39): the tablet clock is only used to *display*
 * time. Each API response carrying `serverTime` is a sample; the sample with
 * the smallest round trip among the recent ones wins (least network skew).
 */
export class ServerClock {
  private samples: { offset: number; rtt: number }[] = [];

  constructor(private readonly local: () => number = () => Date.now()) {}

  sample(serverIso: string, sentAt: number, receivedAt: number): void {
    const server = Date.parse(serverIso);
    if (Number.isNaN(server) || receivedAt < sentAt) return;
    this.samples.push({ offset: server - (sentAt + receivedAt) / 2, rtt: receivedAt - sentAt });
    if (this.samples.length > 10) this.samples.shift();
  }

  get offsetMs(): number {
    if (this.samples.length === 0) return 0;
    return this.samples.reduce((best, s) => (s.rtt < best.rtt ? s : best)).offset;
  }

  /** Current server time in epoch ms. */
  now(): number {
    return this.local() + this.offsetMs;
  }
}

export const clock = new ServerClock();
