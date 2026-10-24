# Changelog

## Current checkout (unreleased)

- Prevented client cleanup from deleting pre-existing download targets: existing targets are refused before the first attempt, and only completed attempts that explicitly report exclusive target creation may be cleaned up on redirect/retry.
- Added a dependency-free regression runner for target ownership, no-body continuation, and socket transport behavior. This checkout does not include the historical full QA tooling referenced below; prior release validation claims are historical, not evidence for this snapshot.

## 1.0.1 - 24/10/2026

### Composer package distribution and documentation hygiene

- Converted the preferred consumer installation model to the normal Composer package `absolutoffice/xcurl`; the legacy standalone autoloader remains supported.
- Removed the root `composer.json` `version` field so released versions are derived from VCS tags, and added Absolut Office author metadata plus package archive exclusions.
- Added an isolated Composer consumer installation smoke test using `vendor/autoload.php`.
- Added a persistent PHPDoc contract gate covering every runtime type and every source-declared runtime method; completed missing method PHPDoc without changing runtime behavior.
- Added a legacy-brand scan. The reviewed 1.0.0 tree contained no legacy organization reference; package metadata is branded Absolut Office.
- Declared the package as `LGPL-3.0-only`, added the root `LICENSE` file, linked it from the README, and made release/package checks require the license metadata and installed license file.
- Refined the API checker to schema 2 by removing parameter-level `ReflectionParameter::isPromoted()` state, which over-froze private implementation detail. The frozen public 1.0 callable/property surface is otherwise unchanged.
- No HTTP, TLS, retry, redirect, framing, transport-selection, error-taxonomy, or public runtime API behavior is intentionally changed.
- The 1.0.1-dev candidate passed all 14 QA gates on Vulcano/PHP 8.5.11 before final promotion; the final promotion changes no runtime behavior or public API.

## 1.0.0 - 10/10/2026

### First stable release

- Promoted the validated `1.0.0-rc2` runtime/API contract to the first stable xCurl release.
- `src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` are byte-for-byte unchanged from `1.0.0-rc2`.
- No HTTP behavior, TLS behavior, retry/redirect policy, request/response framing rule, error taxonomy, transport-selection rule, or consumer contract changed in the final promotion.
- Retained the ten-scenario transport fire matrix, the one-by-one browser fire controls, the standalone runtime release gate, and the reflection-level API compatibility gate as ongoing 1.x QA protections.
- The `public-api-1.0-candidate.json` filename is retained for continuity; its contents are the normative 1.0 compatibility baseline.

## 1.0.0-rc2 - 10/10/2026

### PHP-CS-Fixer cleanup for fire harness

- Applied the configured `braces_position` style to `runFireChild()` in `tools/fire-matrix.php`.
- No PHP runtime source, autoloader, public API snapshot, HTTP behavior, TLS behavior, retry/redirect policy, request/response framing rule, or transport-selection behavior changed from `1.0.0-rc1`.
- The Vulcano `1.0.0-rc1` run had `smoke`, `api`, `release`, `stan`, `unit`, `integration`, `fire`, `consumer`, and `docs` green; only `cs` failed on this formatting rule.
- `src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` remain byte-for-byte unchanged.

## 1.0.0-rc1 - 10/10/2026

### First 1.0 release candidate

- Promoted the reviewed 0.9.x API/runtime contract to the first 1.0 release candidate.
- No PHP runtime source, autoloader, public API snapshot, HTTP behavior, TLS behavior, retry/redirect policy, request/response framing rule, or transport-selection behavior changed from 0.9.5-dev.
- Updated only release/version metadata and release-candidate documentation; the production runtime code remains the frozen 0.9.5-dev code, with `VERSION` promoted to `1.0.0-rc1`.
- Retained the ten-scenario transport fire matrix and the individual `tests/index.php` controls as pre-1.0 acceptance checks.
- Final `1.0.0` promotion remains blocked by any failing QA/fire scenario or demonstrated contract defect.

## 0.9.5-dev - 10/10/2026

### One-by-one browser fire controls

- Added a shared fire-scenario catalog used by both the CLI fire matrix and `tests/index.php`, preventing the browser controls from drifting from the authoritative acceptance matrix.
- Added `--scenario=<id>` to `tools/fire-matrix.php` so one canonical degraded-runtime case can be executed independently.
- Added one **Run** control per fire scenario to `tests/index.php`; each action launches only the fixed PHP CLI fire harness against the bundled local TLS fixture.
- Browser fire execution is restricted to local/private clients, requires a full three-transport baseline, validates a same-site form token, and accepts neither arbitrary commands nor arbitrary URLs.
- `composer test:fire` and the full `qa:report` gate remain unchanged and continue to execute the entire matrix.
- `src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` remain unchanged from the fully green 0.9.3-dev Vulcano runtime/API baseline.

## 0.9.4-dev - 10/10/2026

### Transport fallback fire matrix

- Added `composer test:fire`, an acceptance-level degraded-runtime matrix using isolated PHP child processes and the real `HttpTransportFactory`.
- The canonical matrix proves all-available, only-cURL, cURL-unavailable, only-streams, cURL+streams-unavailable/socket-only, and no-transport behavior.
- Added equivalent strict-mode fire cases, including proof that HTTPS streams are never selected by `createStrict()`.
- Successful fire scenarios perform a real HTTPS request against the bundled local TLS fixture; unavailable scenarios verify that factory creation fails deterministically.
- Added the `fire` gate to `composer qa`, `composer qa:report`, and browser QA-log viewing.
- The fire matrix explicitly tests availability fallback only; xCurl still performs no per-request transport failover after selection.
- `src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` remain unchanged from the fully green 0.9.3-dev Vulcano baseline.

## 0.9.3-dev - 10/10/2026

### PHP-CS-Fixer cleanup for release checker

- Applied the configured indentation style to the flexible nowdoc in `tools/release-contract.php`.
- 0.9.2-dev already passed smoke, API, release-contract, PHPStan, unit, integration, consumer, and docs on PHP 8.5.11; this revision addresses the single remaining PHP-CS-Fixer finding.
- `src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` remain unchanged.
- No HTTP behavior, TLS behavior, retry/redirect policy, transport selection, release-contract semantics, or public API signature changes from 0.9.2-dev.

## 0.9.2-dev - 10/10/2026

### Release/runtime freeze hardening

- Added dependency-free `composer release:check` to validate release metadata and the production-runtime packaging contract independently from the public API snapshot.
- The release gate requires `VERSION`/Composer version alignment, runtime requirements exactly `php:^8.5`, and the expected `AbsolutOffice\\XCurl\\ => src/` PSR-4 mapping.
- Added runtime-tree safety checks for required regular files, no symlinks, and PHP-only sources under `src/`.
- Added a relocated runtime-only smoke check under a project path containing spaces and an unrelated current working directory, with no Composer/vendor/tests/tools/API metadata deployed.
- The relocated check autoloads every type in the frozen 1.0 API snapshot and exercises basic public value/factory APIs without network I/O.
- Added the `release` gate to `composer qa`, `composer qa:report`, and browser QA-log viewing.
- Added `RELEASE-1.0-CANDIDATE.md` to define the production tree and promotion rule.
- No runtime source, public API snapshot, HTTP behavior, TLS behavior, retry/redirect policy, or transport-selection change from 0.9.1-dev.

## 0.9.1-dev - 10/10/2026

### PHP-CS-Fixer cleanup for API checker

- Applied the configured `phpdoc_separation` style in `tools/api-contract.php`.
- The 1.0 candidate snapshot remains byte-for-byte unchanged; `composer api:check` already passed on Vulcano.
- No runtime source, HTTP behavior, TLS behavior, retry/redirect policy, transport selection, or public API signature changes from 0.9.0-dev.
- 0.9.0-dev already passed smoke, API, PHPStan, unit, integration, consumer, and docs on PHP 8.5.11; this revision addresses the single remaining PHP-CS-Fixer finding.

## 0.9.0-dev - 10/10/2026

### Mechanical public-API freeze

- Added `api/public-api-1.0-candidate.json`, a reflection-level snapshot of the 20 reviewed consumer/extension types intended for the 1.0 line.
- Added dependency-free `composer api:check`, covering type kind/inheritance, public constants/properties/methods, parameter names/order/types/defaults, return types, and enum cases.
- Added runtime-type classification so a new PHP type under `src/` cannot silently become accidental public API.
- Explicitly retained the previously documented exclusions for implementation/test helpers (`Internal\*`, `RequestValidator`, system clock/sleeper, transport capability helpers, and factory pure-selection helpers).
- Added `API-0.9.0-dev.md` and `api/README.md` with the freeze/update rule: snapshot changes require deliberate compatibility review.
- Added the `api` gate to `composer qa`, `composer qa:report`, and the browser QA-log viewer.
- No intentional HTTP wire behavior, TLS behavior, transport-selection policy, retry/redirect behavior, or public runtime signature changes from 0.8.1-dev.

## 0.8.1-dev - 10/10/2026

### Vulcano QA cleanup / static-contract alignment

- Applied the single PHP-CS-Fixer `single_quote` correction in `RequestValidatorTest`.
- Removed a redundant null-coalescing fallback for `stream_get_meta_data()['timed_out']`; PHP 8.5 exposes that key as a non-nullable boolean in the native array shape.
- Aligned `HttpRequest` PHPDoc with its intentional runtime behavior: integer-keyed header arrays are accepted and normalized with `array_values()`.
- Corrected the direct-socket invalid-target test connector so its static callable return type remains `resource` even though validation prevents the connector from being invoked.
- Added explicit rejection-count assertions to three exception-loop tests so PHPUnit 13 no longer reports them as risky.
- No HTTP wire behavior, transport selection, retry/redirect policy, TLS policy, or runtime API call signature changes from 0.8.0-dev.

## 0.8.0-dev - 10/10/2026

### Adversarial contract hardening / API-freeze candidate

- Added `API-0.8.0-dev.md` to define the candidate public surface before 1.0 and explicitly separate supported consumer API from implementation/test helpers.
- Tightened request/redirect URI handling to ASCII RFC 3986 syntax: raw non-ASCII/whitespace/control data, malformed percent-encoding, invalid DNS/IP hosts, URL userinfo, and `CONNECT` are rejected consistently before network I/O.
- Tightened request header validation for unsafe control bytes and duplicate `Host` fields, and added target/CA path validation for empty or NUL-containing paths.
- Reordered `SocketHttpTransport` constructor options to the same public prefix as the other concrete transports: `caFile`, `maxResponseBytes`, `maxRequestBytes`; its deterministic connector is now the final internal test hook. This is the only intentional source-level constructor break from 0.7.x.
- Hardened redirects: duplicate `Location` fields are invalid when following is enabled; cross-origin redirects now strip `Cookie` as well as `Authorization` by default; both credential-stripping rules are configurable; `maxRedirects: 0` explicitly means do not follow.
- Hardened response parsing: unsafe response-field controls are rejected, `205 Reset Content` uses no-body semantics, and direct-socket chunk trailers are validated and framing is re-evaluated after trailers.
- Split direct-socket TCP connect from TLS negotiation so certificate/hostname/handshake failures classify as non-transient `TransportError::TLS` instead of retryable connection errors.
- Preserved an explicit empty query (`?`) in the direct-socket request target for backend parity.
- Tightened public value-object validation for header element types and in-memory response byte counts; retry methods must be valid HTTP method tokens.
- Marked `RequestValidator`, concrete transport capability-function helpers, factory pure-selection helpers, and system clock/sleeper implementations as internal/not part of the 1.0 freeze candidate.
- Expanded unit, integration, smoke, browser, and adversarial socket coverage for the above contracts.

## 0.7.1-dev - 10/10/2026

### PHP-CS-Fixer cleanup

- Applied the configured `braces_position` style to `HttpClient::send()`.
- This revision is formatting-only; runtime behavior, public API, retry/deadline semantics, transports, and tests are unchanged from 0.7.0-dev.
- 0.7.0-dev already passed smoke, PHPStan, unit, integration, consumer, and docs on PHP 8.5.11; this revision addresses the single remaining PHP-CS-Fixer finding.

## 0.7.0-dev - 10/10/2026

### Client API stabilization

- Added immutable `HttpRequest` and `Client\RequestOptions` value objects for the high-level client API.
- Added `HttpClient::send(HttpRequest, RequestOptions)` as the preferred extensible entry point while retaining the scalar `request()` method as a source-compatible convenience wrapper.
- Kept `HttpTransportInterface` unchanged: external transport implementations still receive the existing scalar single-attempt contract.
- Made deadline handling consistent for HTTP-status retries: when policy requires a retry but the delay plus another attempt no longer fits the deadline, the client now fails with `ClientError::DEADLINE` instead of returning the retryable response as final.
- Extended unit, smoke, browser, and isolated-consumer coverage for the object API and legacy wrapper equivalence.
- The standalone consumer fixture now uses the object API for memory, raw-body, and file-target requests and also verifies that the scalar client API remains operational.

## 0.6.1-dev - 10/10/2026

### PHPStan fixture cleanup

- Narrowed the TLS fixture request-body read size explicitly to a positive integer before calling `fread()`.
- This is a test-fixture/static-analysis correction only; runtime xCurl transport/client behavior and public APIs are unchanged from 0.6.0-dev.
- 0.6.0-dev already passed smoke, CS-Fixer, unit, integration, consumer, and docs on PHP 8.5.11; this revision addresses the single remaining PHPStan level-10 finding.

## 0.6.0-dev - 10/10/2026

### Generic request bodies

- Added an optional raw binary-safe request body to `HttpTransportInterface::request()` and `Client\HttpClient::request()`. Existing calls remain source-compatible; external transport implementations must add the new optional parameter.
- Added shared request framing: xCurl validates or synthesizes an exact `Content-Length` for explicit request bodies and rejects request `Transfer-Encoding`. Duplicate or mismatched request `Content-Length` values are rejected before network I/O.
- Added optional `maxRequestBytes` limits to concrete transports and factory creation methods, with permanent `TransportError::REQUEST_LIMIT` failures.
- cURL now suppresses libcurl-generated `Expect` / `Content-Type` defaults when the caller did not provide those semantic headers, keeping raw-body behavior aligned with streams and sockets.
- PHP streams send the raw body through the native HTTP context; the direct TLS socket writes headers and body separately without concatenating a second full request copy.
- `Client\HttpClient` preserves request bodies across retries and 307/308 redirects; a 303 method conversion to GET drops the body and entity headers.
- Added unit, integration, isolated-consumer, smoke, and browser-diagnostic coverage for request-body framing, binary bodies, request limits, and redirect body semantics.
- HEAD with an explicit request body is rejected to keep behavior deterministic across all backends.

## 0.5.0-dev - 10/10/2026

### Generic consumer contract

- Reframed xCurl explicitly as a consumer-neutral local package for any PHP 8.5 project, regardless of project name, namespace, framework, database, or business domain.
- Added `tests/consumer-smoke.php`, which copies only `autoload.php`, `VERSION`, and `src/` into a temporary `[BaseDir]/lib/xCurl` tree with no `vendor/` directory and starts a clean PHP process against that isolated runtime package.
- Added an external consumer fixture that implements `HttpTransportInterface` outside the xCurl namespace and exercises `Client\HttpClient` with both in-memory and file-target responses.
- Added a runtime-neutrality guard that rejects consumer/provider-specific names from `src/` and `autoload.php`.
- Added a dependency-surface guard requiring Composer runtime requirements to contain only PHP.
- Added the dedicated `composer test:consumer` quality gate and `consumer` output/log entry to `composer qa:report` and the browser diagnostics page.
- No transport, retry, redirect, TLS, framing, or public runtime API behavior changes from 0.4.1-dev.

## 0.4.1-dev - 09/10/2026

### PHPStan level-10 cleanup

- Removed redundant local `@var` assertions in `RetryPolicy` where PHPStan 2.3 already infers stricter non-negative/status-code/uppercase-string ranges.
- Removed the redundant `positive-int` assertion in `SystemSleeper`; the validated multiplication is already inferred as `int<1000, max>`.
- Typed the TLS fixture retry counter map so path-indexed increments remain statically safe.
- Removed a PHPUnit string assertion that is statically redundant because `gmdate()` is natively typed as `string`.
- No runtime policy or public API behavior changes from 0.4.0-dev.

## 0.4.0-dev - 09/10/2026

### Client / policy layer

- Added `Client\HttpClient` above `HttpTransportInterface`; transports still execute exactly one HTTPS request and never retry or redirect internally.
- Added manual redirects for 301/302/303/307/308, relative `Location` resolution, HTTPS-only continuation, cross-origin `Authorization` stripping, and explicit `Host` removal on redirected requests.
- Added default retry policy for transient transport failures, HTTP 408/429/5xx, GET/HEAD only, with two retries and 1 s / 3 s backoff.
- Added `Retry-After` support for delta-seconds and HTTP-date with a configurable maximum (60 s by default).
- Added optional whole-operation deadlines across redirects, retries, and backoff sleeps while preserving the existing per-transport timeout semantics.
- Added injected `ClockInterface` and `SleeperInterface` plus native implementations so retry/deadline tests do not require real waits.
- Added typed client result/history (`HttpClientResult`, `HttpClientAttempt`, `HttpClientAction`) and typed client failures (`HttpClientException`, `ClientError`).
- Added safe target-file continuation: bodies from redirect/retry responses are removed before the next attempt, while transport-failure cleanup remains owned by the transport so pre-existing targets are never deleted by the client.
- Extended the deterministic TLS fixture and integration suite to exercise client redirects, retries, and final-target behavior across every available transport.
- Updated phpDocumentor version metadata to the current package version.

## 0.3.3-dev - 09/10/2026

### Response-size hardening

- Added an optional per-transport maximum response-body size, enforced for in-memory and file-target requests.
- Added `TransportError::RESPONSE_LIMIT`; configured size-limit failures are permanent/non-transient.
- Added shared internal `ResponseSizePolicy` for limit validation, known `Content-Length` pre-checks, bounded stream reads, and chunk-by-chunk enforcement.
- `HttpTransportFactory::create()`, `createStrict()`, and `createNamed()` now accept an optional `maxResponseBytes` argument while remaining backward compatible with existing no-argument calls.
- Added integration coverage for exact-limit success, known-length rejection, chunked/EOF rejection, target cleanup, and no-body statuses.
- Documented the existing `timeoutSeconds` contract explicitly: it is a transport safety timeout, not a promise of identical wall-clock deadline semantics across cURL, streams, and sockets.
- Incremental upgrade patches are now delivered under `tests/patch/` together with modified files only.

## 0.3.2-dev - 08/10/2026

- Fixed PHP-CS-Fixer `single_line_empty_body` findings in cURL and streams constructors.
- Narrowed optional cURL CA file to a non-empty string before assigning `CURLOPT_CAINFO`.
- Made cURL error classification resilient to constants that are absent in a particular PHP/libcurl build.
- Removed direct dependency on `CURLE_PEER_FAILED_VERIFICATION`, which is not defined on the target PHP 8.5.11 build.
- Tightened the TLS fixture CLI argument and regex handling for PHPStan level 10.
- No public request API changes from 0.3.1-dev.

## 0.3.1-dev - 08/10/2026

### PHP 8.5.11 QA follow-up

- Fixed PHPStan level-10 caller noise by documenting the public transport contract with the native scalar input types that are actually accepted; `RequestValidator` remains responsible for rejecting empty/invalid values and narrowing them internally.
- Made the integration TLS trust store deterministic per transport instead of relying on `SSL_CERT_FILE` / `CURL_CA_BUNDLE` changes made after the PHP process had already started.
- Added an optional CA bundle path to cURL, streams, and socket transport constructors. The integration suite passes its private test CA explicitly and normal runtime behavior remains unchanged when the option is omitted.
- cURL now maps certificate / TLS verification failures to `TransportError::TLS` instead of the generic `UNKNOWN` category where the libcurl error code is identifiable.
- The cURL adversarial expectations remain strict: truncated fixed-length responses are transient read failures, read timeouts are transient timeout failures, 101 is a protocol error, and `Transfer-Encoding` plus `Content-Length` is a protocol error.

## 0.3.0-dev - 08/10/2026

### Adversarial HTTP integration

- Added a deterministic local TLS fixture and PHPUnit integration suite.
- Added transport-parity checks for fixed-length, chunked, EOF-delimited, interim, no-body, redirect, repeated-header, truncation, timeout, and TLS-hostname-mismatch behavior.
- Added adversarial checks for 101, duplicate/conflicting `Content-Length`, ambiguous `Transfer-Encoding` + `Content-Length`, and malformed chunked responses.
- Added a shared internal `ResponseHeaderPolicy` so cURL, streams, and socket normalize final header blocks consistently where metadata is available.
- cURL now re-checks response framing even when libcurl itself returns an error, allowing 101/conflicting-framing errors to remain protocol failures instead of being flattened into generic read errors.
- Removed `curl_close()` usage because it is deprecated in PHP 8.5 and has been a no-op since PHP 8.0.
- Prefer `CURLOPT_PROTOCOLS_STR = HTTPS` when available, with the legacy bitmask only as a compatibility fallback.

### Strict-framing capability

- Integration testing demonstrated that PHP HTTPS streams can hide `Transfer-Encoding` from `wrapper_data` after the native wrapper processes the response; malformed chunked bodies can therefore be impossible to validate independently in userland.
- Preserved the default compatibility order `curl -> streams -> socket`.
- Added `HttpTransportFactory::createStrict()` and strict selection `curl -> socket` for consumers that require xCurl-enforced response framing.
- Added `selectedStrict` and `strictFraming` to factory diagnostics.

### Request parity

- Moved request `Transfer-Encoding` and non-zero `Content-Length` rejection into `RequestValidator`, so all transports enforce the same no-request-body API contract.
- QA reporting now runs unit and integration suites separately.

## 0.2.2 - 2026-10-08

- Applied PHP-CS-Fixer 3.95.27 formatting findings from the PHP 8.5.11 validation run.
- Removed redundant `unset()` calls rejected by PHPStan 2.3.0 after URL-shape narrowing.
- Added concise QA report tooling so server-only differences can be shared without full console dumps.

## 0.2.1-local - 08/10/2026

### QA fixes from PHP 8.5.11 validation

- Enabled risky PHP-CS-Fixer rules explicitly because `declare_strict_types` is intentionally configured.
- Added a Composer root package version to avoid the local `1.0.0` fallback warning.
- Fixed request-method validation so CR/LF and surrounding whitespace are rejected instead of being removed by `trim()`.
- Refined PHPDoc array shapes and resource/list annotations for PHPStan level 10.
- Normalized validated URL metadata so `scheme`, `host`, and `port` are guaranteed keys.
- Reworked one-line multi-tag PHPDoc blocks that PHPStan did not interpret as intended.
- Removed redundant branches identified by PHPStan and simplified stream timeout metadata checks.
- Added runtime invariants to `HttpResponse` for HTTP status and non-negative byte counts.

### Expected quality gate

Run on PHP 8.5.11 after updating the package:

```bash
composer cs:check
composer stan
composer test
composer docs
composer qa
```

## 0.2.0-local - 08/10/2026

### Development baseline

- Set the local package baseline to PHP 8.5, with PHP 8.5.11 as the reference Apache/CLI platform.
- Added a development-only `composer.json`; runtime remains independent from Composer and `vendor/`.
- Added PHPStan 2.3 configuration at level 10 and PHP 8.5.11 analysis target.
- Added PHP-CS-Fixer configuration using PER-CS 3.x and PHP 8.5 migration rules.
- Added PHPUnit 13.4 configuration and unit-test structure.
- Added phpDocumentor configuration and expanded PHPDoc on the public API.
- Added `tools/diagnose.php` to compare transport capabilities across PHP SAPIs.

### Tests

- Retained dependency-free smoke tests.
- Added PHPUnit tests for transport selection, response headers, request validation, IPv6 authority handling, socket Content-Length framing, chunked framing, no-body responses, and ambiguous TE+CL rejection.

### Runtime design

- Runtime package remains located at `[BaseDir]/lib/xCurl`.
- No MariaDB dependency was introduced; database configuration is outside the transport package scope.

## 0.1.0-local

- Initial local package extraction.
- HTTPS-only validation shared by all transports.
- cURL, HTTPS stream, and direct TLS socket implementations.
- Runtime factory selection in cURL -> streams -> socket order.
- Partial-download cleanup and transport error categorization.
