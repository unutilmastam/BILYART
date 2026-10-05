/* eslint-disable */
/**
 * GENERATED FILE — do not edit. Source: packages/protocol/schemas/*.schema.json
 * Regenerate: npm run generate (in packages/protocol)
 */

/**
 * ULID exposed in APIs
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "PublicId".
 */
export type PublicId = string;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "EpochSeconds".
 */
export type EpochSeconds = number;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "IsoUtc".
 */
export type IsoUtc = string;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "MoneyUzs".
 */
export type MoneyUzs = number;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceCode".
 */
export type DeviceCode = string;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletCode".
 */
export type TabletCode = string;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "PairingCode".
 */
export type PairingCode = string;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "SemVer".
 */
export type SemVer = string;
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "LightState".
 */
export type LightState = 'ON' | 'OFF' | 'WARNING';
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "SessionStatus".
 */
export type SessionStatus =
  'RESERVED' | 'STARTING' | 'ACTIVE' | 'COMPLETING' | 'COMPLETED' | 'CANCELLED' | 'FAILED';
/**
 * Derived display status of a table
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TableStatus".
 */
export type TableStatus =
  'AVAILABLE' | 'RESERVED' | 'STARTING' | 'BUSY' | 'WARNING' | 'DISABLED' | 'DEVICE_OFFLINE' | 'CLOSED';
/**
 * Relay channel on the device (1-based). One channel = one table lamp.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "Channel".
 */
export type Channel = number;
/**
 * Command delivered in /poll responses. Device ignores commands with expiresAt < serverTime and deduplicates by commandId.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceCommand".
 */
export type DeviceCommand =
  | StartSessionCommand
  | StopSessionCommand
  | WarningCommand
  | SyncCommand
  | PingCommand
  | ConfigUpdateCommand
  | OtaCommand;

/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "ApiError".
 */
export interface ApiError {
  error: {
    code: string;
    message: string;
    /**
     * Validation errors per field
     */
    fields?: {
      [k: string]: string[] | undefined;
    };
    requestId?: string;
  };
}
/**
 * POST /device/v1/ack
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceAckRequest".
 */
export interface DeviceAckRequest {
  /**
   * @minItems 1
   * @maxItems 20
   */
  acks: DeviceAck[];
}
export interface DeviceAck {
  commandId: PublicId;
  result: 'OK' | 'ERROR' | 'IGNORED';
  error?: string | null;
  state?: LightState;
  channel?: Channel;
}
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceAckResponse".
 */
export interface DeviceAckResponse {
  serverTime: EpochSeconds;
  accepted: PublicId[];
}
/**
 * POST /device/v1/cash/notes (Idempotency-Key) — bills taken by the acceptor, oldest first. Each bill is written to flash BEFORE sending and removed only after the server answered for its noteUid.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceCashNotesRequest".
 */
export interface DeviceCashNotesRequest {
  /**
   * @minItems 1
   * @maxItems 20
   */
  notes: {
    /**
     * Unique per device forever, e.g. <deviceCode>-<flash counter>.
     */
    noteUid: string;
    /**
     * UZS bill value decoded from the pulses.
     */
    nominal: 1000 | 2000 | 5000 | 10000 | 20000 | 50000 | 100000 | 200000;
    /**
     * The session the box was accepting for (from the last poll).
     */
    sessionId?: PublicId | null;
    deviceTs?: number | null;
  }[];
}
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceCashNotesResponse".
 */
export interface DeviceCashNotesResponse {
  results: {
    noteUid: string;
    /**
     * Every status means: stored, delete it from flash.
     */
    status: 'CREDITED' | 'UNASSIGNED' | 'DUPLICATE';
  }[];
  serverTime: EpochSeconds;
  pollIntervalSec: number;
  /**
   * Open the acceptor (inhibit off). Close it when false, when the server is unreachable for 5 s, or past acceptUntil.
   */
  accept: boolean;
  sessionId: PublicId | null;
  required: MoneyUzs;
  paid: MoneyUzs;
  acceptUntil: EpochSeconds | null;
}
/**
 * POST /device/v1/cash/poll — bill acceptor heartbeat (every pollIntervalSec).
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceCashPollRequest".
 */
export interface DeviceCashPollRequest {
  ts: EpochSeconds;
  fw: SemVer;
  /**
   * Acceptor inhibit is open right now.
   */
  accepting: boolean;
  /**
   * Bills stored in flash and not yet confirmed by the server.
   */
  queued: number;
  rssi?: number | null;
  uptime?: number | null;
  bootReason?: string | null;
}
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceCashPollResponse".
 */
export interface DeviceCashPollResponse {
  serverTime: EpochSeconds;
  pollIntervalSec: number;
  /**
   * Open the acceptor (inhibit off). Close it when false, when the server is unreachable for 5 s, or past acceptUntil.
   */
  accept: boolean;
  sessionId: PublicId | null;
  required: MoneyUzs;
  paid: MoneyUzs;
  acceptUntil: EpochSeconds | null;
}
export interface StartSessionCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'START_SESSION';
  payload: {
    channel: Channel;
    sessionId: PublicId;
    startAt: EpochSeconds;
    endAt: EpochSeconds;
    warnBeforeSec: number;
    flashCount: number;
  };
}
export interface StopSessionCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'STOP_SESSION';
  payload: {
    channel: Channel;
    sessionId: PublicId;
  };
}
export interface WarningCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'WARNING';
  payload: {
    channel: Channel;
    sessionId: PublicId;
    flashCount: number;
  };
}
export interface SyncCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'SYNC';
  payload: {};
}
export interface PingCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'PING';
  payload: {};
}
export interface ConfigUpdateCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'CONFIG_UPDATE';
  payload: DeviceConfig;
}
export interface DeviceConfig {
  pollIntervalSec: number;
  maxSessionSec: number;
  warnBeforeSec: number;
  flashCount: number;
}
export interface OtaCommand {
  commandId: PublicId;
  expiresAt: EpochSeconds;
  type: 'OTA';
  payload: {
    version: SemVer;
    sha256: string;
    size: number;
  };
}
/**
 * GET /device/v1/pairing-status (Authorization: PollToken <pollToken>). token is returned exactly once.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DevicePairingStatusResponse".
 */
export interface DevicePairingStatusResponse {
  status: 'WAITING' | 'PAIRED' | 'EXPIRED';
  /**
   * Present only in the first PAIRED response
   */
  token?: string;
  serverTime: EpochSeconds;
}
/**
 * POST /device/v1/poll — also the heartbeat. ts is the device clock (informational only). One entry per relay channel. Tenant/branch/table are never accepted from the device.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DevicePollRequest".
 */
export interface DevicePollRequest {
  ts: EpochSeconds;
  fw: SemVer;
  /**
   * @minItems 1
   * @maxItems 8
   */
  channels: DeviceChannelState[];
  rssi?: number;
  uptime?: number;
  bootReason?: string;
  lastAppliedCommandId?: PublicId | null;
}
export interface DeviceChannelState {
  channel: Channel;
  state: LightState;
  sessionId?: PublicId | null;
  endAt?: EpochSeconds | null;
}
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DevicePollResponse".
 */
export interface DevicePollResponse {
  serverTime: EpochSeconds;
  /**
   * @maxItems 10
   */
  commands: DeviceCommand[];
  pollIntervalSec: number;
}
/**
 * POST /device/v1/register
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceRegisterRequest".
 */
export interface DeviceRegisterRequest {
  /**
   * eFuse MAC, uppercase hex
   */
  hardwareId: string;
  firmwareVersion: SemVer;
  registrationSecret: string;
  /**
   * Number of relay channels this device drives (default 1).
   */
  channelCount?: number;
  /**
   * LIGHT = table lamp relay controller (default); CASH = bill acceptor box, one per branch.
   */
  kind?: 'LIGHT' | 'CASH';
}
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceRegisterResponse".
 */
export interface DeviceRegisterResponse {
  deviceCode: DeviceCode;
  pairingCode: PairingCode;
  pairingExpiresAt: EpochSeconds;
  /**
   * Only valid for GET /pairing-status
   */
  pollToken: string;
  serverTime: EpochSeconds;
}
/**
 * GET /device/v1/state — authoritative state after boot/reconnect (SYNC).
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "DeviceStateResponse".
 */
export interface DeviceStateResponse {
  serverTime: EpochSeconds;
  /**
   * Running sessions of this device, at most one per channel.
   *
   * @maxItems 8
   */
  sessions: DeviceStateSession[];
  config: DeviceConfig;
}
export interface DeviceStateSession {
  channel: Channel;
  sessionId: PublicId;
  startAt: EpochSeconds;
  endAt: EpochSeconds;
  status: 'STARTING' | 'ACTIVE';
}
/**
 * GET /api/tablet/bootstrap — everything the kiosk needs after start. Also cached (IndexedDB) for offline display only.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletBootstrapResponse".
 */
export interface TabletBootstrapResponse {
  serverTime: IsoUtc;
  tablet: {
    id: PublicId;
    code: TabletCode;
  };
  branch: {
    id: PublicId;
    name: string;
    tenantName: string;
    timezone: string;
    isOpenNow: boolean;
    paymentMode?: 'CASHIER' | 'BILL_ACCEPTOR';
    /**
     * Bill acceptor reachable; null when the branch pays at the cashier.
     */
    cashOnline?: boolean | null;
  };
  settings: {
    locale: 'uz' | 'ru';
    photoRequired: boolean;
    privacyNotice: string;
    warnBeforeSec: number;
    warningAudio: {
      mode: 'CLIPS' | 'TTS';
      /**
       * Template, e.g. "{table}-stol, sizda 5 daqiqa vaqtingiz qoldi."
       */
      text: string;
      clipUrls?: string[];
    };
  };
  tables: TabletTable[];
}
/**
 * A table as the kiosk sees it. status is derived server-side.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletTable".
 */
export interface TabletTable {
  id: PublicId;
  number: number;
  name: string;
  status: TableStatus;
  session: null | TabletTableSession;
  pricing: null | TabletPricing;
}
export interface TabletTableSession {
  id: PublicId;
  status: SessionStatus;
  startAt: IsoUtc | null;
  endAt: IsoUtc | null;
}
export interface TabletPricing {
  type: 'HOURLY';
  pricePerHour: MoneyUzs;
  durations: TabletDurationQuote[];
}
export interface TabletDurationQuote {
  minutes: number;
  amount: MoneyUzs;
}
/**
 * GET /api/tablet/pairing-status (Authorization: PollToken <pollToken>). token is returned exactly once.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletPairingStatusResponse".
 */
export interface TabletPairingStatusResponse {
  status: 'WAITING' | 'PAIRED' | 'EXPIRED';
  token?: string;
  serverTime: IsoUtc;
}
/**
 * POST /api/tablet/register (public, rate-limited). Starts tablet pairing; the code is shown on the tablet screen.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletRegisterRequest".
 */
export interface TabletRegisterRequest {
  appVersion: SemVer;
  deviceModel?: string;
}
/**
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletRegisterResponse".
 */
export interface TabletRegisterResponse {
  tabletCode: TabletCode;
  pairingCode: PairingCode;
  pairingExpiresAt: IsoUtc;
  pollToken: string;
  serverTime: IsoUtc;
}
/**
 * POST /api/tablet/sessions/prepare (Idempotency-Key required). Reserves the table for a short time.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletSessionPrepareRequest".
 */
export interface TabletSessionPrepareRequest {
  tableId: PublicId;
  durationMinutes: number;
}
/**
 * Response of prepare / photo / start / cancel / show.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletSession".
 */
export interface TabletSession {
  serverTime: IsoUtc;
  session: {
    id: PublicId;
    tableId: PublicId;
    tableNumber: number;
    status: SessionStatus;
    durationMinutes: number;
    amount: MoneyUzs;
    reservedUntil: IsoUtc | null;
    startAt: IsoUtc | null;
    endAt: IsoUtc | null;
    hasPhoto: boolean;
    failureReason?: string | null;
    payment?: null | {
      mode: 'BILL_ACCEPTOR';
      paid: MoneyUzs;
      /**
       * The acceptor takes bills for this session right now.
       */
      accepting: boolean;
      acceptUntil: IsoUtc | null;
    };
  };
}
/**
 * GET /api/tablet/tables — polled every few seconds.
 *
 * This interface was referenced by `ProtocolRoot`'s JSON-Schema
 * via the `definition` "TabletTablesResponse".
 */
export interface TabletTablesResponse {
  serverTime: IsoUtc;
  isOpenNow: boolean;
  tables: TabletTable[];
  cashOnline?: boolean | null;
}
