# xCurl 1.0.1 API status

`1.0.1` intentionally changes no xCurl runtime signature or HTTP behavior. The frozen public target remains `1.0` and `composer api:check` still reports the same 20 public types.

The only compatibility-checker change is the snapshot-format migration from schema 1 to schema 2. Parameter-level `ReflectionParameter::isPromoted()` state is removed because changing a private promoted property into an explicit private property does not change the callable signature. Public properties remain captured independently.

The source changes under `src/` are PHPDoc-only. Removing comments and whitespace from the `1.0.0` and `1.0.1` runtime trees yields the same PHP token stream.
