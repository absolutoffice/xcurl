# xCurl 1.0.0 checks

`1.0.0` promotes the fully accepted `1.0.0-rc2` runtime/API contract without changing runtime source, autoloading code, the 1.0 API snapshot, or the fire harness. Only release/version metadata and final-release documentation change.

The reference host is Vulcano with PHP 8.5.11. The `1.0.0-rc2` acceptance cycle was reported with all QA gates green before this final promotion.

## Final automated acceptance

After applying the final delta, run:

```bash
composer update --lock
composer qa:report
```

Expected gates:

```text
[OK] smoke
[OK] api
[OK] release
[OK] cs
[OK] stan
[OK] unit
[OK] integration
[OK] fire
[OK] consumer
[OK] docs
```

The `fire` gate remains authoritative and runs the complete ten-scenario degraded-runtime matrix with `--require-full` on Vulcano. No fire scenario may be skipped on the reference host.

`tests/index.php` retains the same whitelisted fire scenarios as individual controls for operator diagnostics. Those controls exercise the same scenario catalog used by the CLI matrix and do not alter the runtime contract.

## Final-release invariants

The final release must preserve these files byte-for-byte from `1.0.0-rc2`:

```text
src/
autoload.php
api/public-api-1.0-candidate.json
```

The production deployment remains only `autoload.php`, `VERSION`, and `src/` under `[BaseDir]/lib/xCurl/`. Composer, `vendor/`, tests, tools, snapshots, and generated documentation remain development assets.
