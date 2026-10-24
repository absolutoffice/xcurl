# Upgrade to xCurl 1.0.0

`1.0.0` is the final promotion of `1.0.0-rc2`. No consuming application source changes are required when upgrading from `1.0.0-rc2`.

`src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` are unchanged. Runtime HTTP/TLS behavior, retry/redirect behavior, request/response framing, transport selection, error taxonomy, and the public API are therefore identical to the validated release candidate.

The final delta changes only release/version metadata and final-release documentation. `VERSION`, Composer package version metadata, and phpDocumentor version metadata become `1.0.0`.

After applying the delta on Vulcano, refresh the development lock metadata and run the complete acceptance suite:

```bash
composer update --lock
composer qa:report
```

All ten gates must remain green. `composer.lock` remains a development reproducibility file and is refreshed on the reference host rather than shipped in this delta.

For production deployment, only the local runtime tree is required:

```text
[BaseDir]/lib/xCurl/
├── autoload.php
├── VERSION
└── src/
```

No API snapshot update is part of this upgrade.
