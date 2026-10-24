# xCurl 1.0.0 release contract

`1.0.0` is promoted from the validated `1.0.0-rc2` code line. The final promotion intentionally changes no runtime implementation and no public API surface.

## Production runtime tree

A consuming project requires only:

```text
[BaseDir]/lib/xCurl/
├── autoload.php
├── VERSION
└── src/
```

Composer, `vendor/`, QA tools, tests, API snapshots, generated documentation, and release metadata are development assets and are not runtime dependencies.

## Frozen 1.0 invariants

The final release carries the same `src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` bytes as `1.0.0-rc2`. The snapshot filename is retained for continuity, but its contents are the normative 1.0 compatibility baseline.

The reference acceptance suite is `composer qa:report` on PHP 8.5.11 and includes smoke, API snapshot, release/runtime packaging, coding style, PHPStan, unit, integration, full transport fire matrix, generic consumer, and documentation gates.

The fire matrix verifies availability fallback through the real factory in isolated PHP child processes. It does not introduce per-request transport failover: once a transport has been selected, request failure is handled by the existing transport/client error and retry policy rather than silently switching backend.

## 1.x maintenance rule

Patch/minor maintenance may fix defects and add backward-compatible behavior, but a change that breaks `composer api:check` is a compatibility event and must not be normalized by blindly rewriting the snapshot. Backward-incompatible public API changes require the appropriate major-version decision.

Runtime remains consumer-neutral: provider names, credentials, payload schemas, checksums, business rules, and consumer-specific integration logic stay outside xCurl.
