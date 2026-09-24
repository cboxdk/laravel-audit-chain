---
title: The hash format
weight: 22
description: Exactly which bytes are hashed for each entry under the audit-chain/v1 codec
---

# The hash format

For every entry:

```
hash = lowercase_hex( SHA-256( canonical(entry) ‖ prev_hash ) )
```

- `‖` is plain concatenation of the canonical bytes and the previous entry's `hash`, as
  its 64 lowercase hex characters.
- The first entry of every chain uses a `prev_hash` of 64 `0` characters
  (`DatabaseAuditChain::GENESIS_HASH`).
- `canonical(entry)` is whatever the bound `EntryCodec` returns. The rest of the
  composition is fixed by the package.

## `audit-chain/v1`

The default codec (`Codec\V1EntryCodec`) produces a JSON object with these keys, in
byte order:

| Key | Value |
|---|---|
| `action` | string |
| `actor_id` | string or `null` |
| `actor_type` | string (an enum-cast column contributes its backed value) |
| `codec` | the literal `"audit-chain/v1"` |
| `context` | the context, canonicalised (below) |
| `extra` | object of configured extra columns; **omitted** when none are configured |
| `ip` | string or `null` |
| `partition` | string: the chain key's partition |
| `recorded_at` | integer: Unix seconds |
| `scope` | string: the chain key's scope |
| `sequence` | integer |
| `target_id` | string or `null` |
| `target_type` | string or `null` |

Canonical JSON (`Codec\CanonicalJson`):

- Objects have their keys sorted as byte strings (`ksort(…, SORT_STRING)`), at every
  depth.
- Lists (`array_is_list`) keep their order. Reordering a list changes its meaning.
- Encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`: `/` and non-ASCII
  characters are written as-is. Quotes, backslashes and control characters are escaped
  as JSON requires.

Both halves of the chain key are hashed, so an entry cannot be moved to another chain
by rewriting its partition or scope column: the chain it lands in no longer verifies.
`codec` is hashed so that a future format can never produce the same bytes for a
different reading of a row.

### A worked example

An entry with partition `tenant_a`, scope `ledger`, sequence 1, actor `user`/`usr_1`,
action `invoice.voided`, target `invoice`/`inv_9`, IP `203.0.113.4`, recorded at
2026-09-01 10:00:00 UTC, and context
`{"tags": ["b", "a"], "reason": "duplicate", "amount": {"value": 1200, "currency": "DKK"}}`
canonicalises to:

```json
{"action":"invoice.voided","actor_id":"usr_1","actor_type":"user","codec":"audit-chain/v1","context":{"amount":{"currency":"DKK","value":1200},"reason":"duplicate","tags":["b","a"]},"ip":"203.0.113.4","partition":"tenant_a","recorded_at":1788256800,"scope":"ledger","sequence":1,"target_id":"inv_9","target_type":"invoice"}
```

and its hash (with the genesis `prev_hash`) is
`f2e46583777a303251490fceb1d53f2249737b915f8e355d111ed70446029147`.

`tests/Unit/V1EntryCodecTest.php` holds the codec to this example and one more. The
expected values there were written from this page and hashed independently, not
produced by running the codec.

## Values to keep out of `context`

The codec reads `context` through the model's `array` cast, both right after the entry
is filled and every time it is read back, so it hashes the same PHP value either way.
Two things can still bite:

- **Floats.** They are rendered in PHP's shortest round-trip form, which is stable for
  a given value on a given build, but it is a needless dependency. Store amounts as
  integers (minor units) or strings.
- **A normalising JSON column.** MySQL's `JSON` type re-serialises what it stores (key
  order, whitespace, escapes). Canonicalisation absorbs that, so hashes still match, but
  the stored text is not the text that was written. The package's own migration stores
  `context` as text for that reason.

## The format is frozen

Changing what a codec emits for an existing row breaks every chain it ever wrote. A new
form is a new codec with a new `version()`, adopted either by a one-time re-chain or by
a codec that reads a stored version column and dispatches. Never edit a codec in place.
