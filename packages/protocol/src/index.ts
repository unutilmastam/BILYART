export * from './generated/protocol';

/** Protocol version shared by server, tablet and firmware. Bump on breaking changes. */
export const PROTOCOL_VERSION = 1 as const;

/** Header carrying the client-generated idempotency key on state-changing tablet/device requests. */
export const IDEMPOTENCY_HEADER = 'Idempotency-Key' as const;
