# xCurl 1.0.0 public API contract

`1.0.0` is the final promotion of the public runtime contract validated in `1.0.0-rc2`. The production runtime implementation and the machine-readable API snapshot are unchanged from that release candidate.

The normative reflection-level compatibility baseline is `api/public-api-1.0-candidate.json`. Its historical filename is retained deliberately; for the 1.x line it is the frozen 1.0 baseline, not a mutable pre-release draft. `composer api:check` must continue to match the same 20 public classes, interfaces, and enums unless an explicit compatibility decision is made.

The snapshot covers public type kind and inheritance, public constants and properties, declared public methods, parameter names/order/types/defaults, return types, and enum cases. Parameter names remain part of the source-compatibility contract because PHP consumers may use named arguments.

`src/`, `autoload.php`, and `api/public-api-1.0-candidate.json` are byte-for-byte unchanged from `1.0.0-rc2`. No HTTP behavior, TLS behavior, retry/redirect policy, request/response framing rule, error taxonomy, transport-selection rule, or consumer contract is intentionally changed by the final promotion.

The intentionally non-frozen implementation/test surface remains excluded from the 1.0 compatibility promise: `Internal\*`, `RequestValidator`, `Client\SystemClock`, `Client\SystemSleeper`, transport `requiredFunctions()` / `missingRequiredFunctions()` helpers, and the factory pure `select*TransportName()` helpers. Consumers should use the documented public factory selection/diagnostic methods instead of those helpers.

For the 1.x line, backward-incompatible changes to the frozen public surface require a corresponding semantic-versioning decision. The snapshot must not be regenerated merely to make QA pass.
