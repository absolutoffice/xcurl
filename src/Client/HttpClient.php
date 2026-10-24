<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\Contract\ClockInterface;
use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\Contract\SleeperInterface;
use AbsolutOffice\XCurl\HttpRequest;
use AbsolutOffice\XCurl\HttpResponse;
use AbsolutOffice\XCurl\HttpTransportException;
use AbsolutOffice\XCurl\Internal\RequestHeaderPolicy;
use AbsolutOffice\XCurl\Internal\UriResolver;
use InvalidArgumentException;

/**
 * Policy layer that adds redirects, retries, Retry-After, and an optional global deadline.
 *
 * The injected transport still performs exactly one HTTPS request per invocation. HttpClient
 * is the only layer that decides whether another request should be attempted.
 */
final class HttpClient
{
    private readonly RetryPolicy $retryPolicy;
    private readonly RedirectPolicy $redirectPolicy;
    private readonly SleeperInterface $sleeper;
    private readonly ClockInterface $clock;

    /**
     * Creates a client around one transport with optional retry, redirect, sleeper, and clock policies.
     */
    public function __construct(
        private readonly HttpTransportInterface $transport,
        ?RetryPolicy $retryPolicy = null,
        ?RedirectPolicy $redirectPolicy = null,
        ?SleeperInterface $sleeper = null,
        ?ClockInterface $clock = null,
    ) {
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->redirectPolicy = $redirectPolicy ?? new RedirectPolicy();
        $this->sleeper = $sleeper ?? new SystemSleeper();
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Executes an HTTPS request using the scalar convenience API.
     *
     * This method remains source-compatible with the pre-0.7 client API. New consumers
     * may prefer send(HttpRequest, RequestOptions) so request data and execution options
     * remain separate as the public client API evolves.
     *
     * @param list<string> $headers
     * @param int|null $deadlineSeconds Optional whole-operation deadline in seconds.
     * @param string|null $requestBody Optional raw binary-safe request body.
     *
     * @throws HttpClientException
     * @throws InvalidArgumentException
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?int $deadlineSeconds = null,
        ?string $requestBody = null,
    ): HttpClientResult {
        return $this->send(
            new HttpRequest($method, $url, $headers, $requestBody),
            new RequestOptions($timeoutSeconds, $targetPath, $deadlineSeconds),
        );
    }

    /**
     * Executes an immutable request with client-level policy.
     *
     * RequestOptions::timeoutSeconds remains the per-transport safety timeout. The optional
     * whole-operation deadline limits redirects, retries, and sleeps. The deadline is
     * enforced between transport calls and also caps the timeout given to each new attempt;
     * it cannot pre-empt a backend more finely than the transport timeout granularity.
     *
     * @throws HttpClientException
     * @throws InvalidArgumentException
     */
    public function send(HttpRequest $request, RequestOptions $options): HttpClientResult
    {
        $deadlineSeconds = $options->deadlineSeconds;
        if ($deadlineSeconds !== null && $deadlineSeconds < 1) {
            throw new InvalidArgumentException('HTTP client deadline must be at least one second.');
        }

        $deadlineAt = $deadlineSeconds === null
            ? null
            : $this->clock->monotonicSeconds() + $deadlineSeconds;

        $currentMethod = strtoupper($request->method);
        $currentUrl = $request->url;
        $currentHeaders = $request->headers;
        $currentBody = $request->body;
        $timeoutSeconds = $options->timeoutSeconds;
        $targetPath = $options->targetPath;
        // Exclusive targets must be free before any attempt, including no-body responses.
        if ($targetPath !== null && (file_exists($targetPath) || is_link($targetPath))) {
            throw new InvalidArgumentException('HTTP download target already exists.');
        }
        $history = [];
        $sequence = 0;
        $redirects = 0;
        $retries = 0;

        while (true) {
            $attemptTimeout = $this->attemptTimeout($timeoutSeconds, $deadlineAt, $history);
            ++$sequence;

            try {
                $response = $this->transport->request(
                    $currentMethod,
                    $currentUrl,
                    $currentHeaders,
                    $attemptTimeout,
                    $targetPath,
                    $currentBody,
                );
            } catch (HttpTransportException $exception) {
                if ($this->shouldRetryTransport($currentMethod, $exception, $retries)) {
                    $retryNumber = $retries + 1;
                    $delay = $this->retryPolicy->delayMilliseconds($retryNumber, null, $this->clock);

                    if ($this->hasTimeForNextAttempt($delay, $deadlineAt)) {
                        ++$retries;
                        $history[] = new HttpClientAttempt(
                            $sequence,
                            $currentMethod,
                            $currentUrl,
                            HttpClientAction::RETRIED,
                            null,
                            $exception->error,
                            $delay,
                        );
                        $this->sleep($delay);
                        continue;
                    }

                    $history[] = new HttpClientAttempt(
                        $sequence,
                        $currentMethod,
                        $currentUrl,
                        HttpClientAction::FAILED,
                        null,
                        $exception->error,
                    );

                    throw new HttpClientException(
                        'HTTP client deadline does not leave enough time for another transport attempt.',
                        ClientError::DEADLINE,
                        $history,
                        $exception,
                    );
                }

                $history[] = new HttpClientAttempt(
                    $sequence,
                    $currentMethod,
                    $currentUrl,
                    HttpClientAction::FAILED,
                    null,
                    $exception->error,
                );

                throw new HttpClientException(
                    'HTTP transport request failed.',
                    ClientError::TRANSPORT,
                    $history,
                    $exception,
                );
            }

            $locations = $response->headerValues('location');
            $isRedirect = $this->redirectPolicy->isRedirectStatus($response->statusCode);
            if ($this->redirectPolicy->maxRedirects > 0 && $isRedirect && count($locations) > 1) {
                $history[] = new HttpClientAttempt(
                    $sequence,
                    $currentMethod,
                    $currentUrl,
                    HttpClientAction::FAILED,
                    $response->statusCode,
                );
                $this->cleanupConsumedTarget($targetPath, $response, $history);

                throw new HttpClientException(
                    'HTTP redirect response contains multiple Location headers.',
                    ClientError::INVALID_REDIRECT,
                    $history,
                );
            }

            $location = $locations[0] ?? null;
            if ($this->redirectPolicy->maxRedirects > 0
                && $location !== null
                && $isRedirect) {
                if ($redirects >= $this->redirectPolicy->maxRedirects) {
                    $history[] = new HttpClientAttempt(
                        $sequence,
                        $currentMethod,
                        $currentUrl,
                        HttpClientAction::FAILED,
                        $response->statusCode,
                    );
                    $this->cleanupConsumedTarget($targetPath, $response, $history);

                    throw new HttpClientException(
                        sprintf('HTTP redirect limit of %d was exceeded.', $this->redirectPolicy->maxRedirects),
                        ClientError::REDIRECT_LIMIT,
                        $history,
                    );
                }

                try {
                    $nextUrl = UriResolver::resolve($currentUrl, $location);
                } catch (InvalidArgumentException $exception) {
                    $history[] = new HttpClientAttempt(
                        $sequence,
                        $currentMethod,
                        $currentUrl,
                        HttpClientAction::FAILED,
                        $response->statusCode,
                    );
                    $this->cleanupConsumedTarget($targetPath, $response, $history);

                    throw new HttpClientException(
                        'HTTP redirect target is invalid or not HTTPS.',
                        ClientError::INVALID_REDIRECT,
                        $history,
                        $exception,
                    );
                }

                if (!$this->hasTimeForNextAttempt(0, $deadlineAt)) {
                    $history[] = new HttpClientAttempt(
                        $sequence,
                        $currentMethod,
                        $currentUrl,
                        HttpClientAction::FAILED,
                        $response->statusCode,
                        null,
                        null,
                        $nextUrl,
                    );
                    $this->cleanupConsumedTarget($targetPath, $response, $history);

                    throw new HttpClientException(
                        'HTTP client deadline does not leave enough time to follow the redirect.',
                        ClientError::DEADLINE,
                        $history,
                    );
                }

                $nextMethod = $this->redirectPolicy->redirectMethod($currentMethod, $response->statusCode);
                $nextHeaders = RequestHeaderPolicy::withoutNames($currentHeaders, ['host']);
                if (!UriResolver::sameOrigin($currentUrl, $nextUrl)) {
                    if ($this->redirectPolicy->stripAuthorizationOnCrossOrigin) {
                        $nextHeaders = RequestHeaderPolicy::withoutNames($nextHeaders, ['authorization']);
                    }
                    if ($this->redirectPolicy->stripCookieOnCrossOrigin) {
                        $nextHeaders = RequestHeaderPolicy::withoutNames($nextHeaders, ['cookie']);
                    }
                }
                $nextBody = $currentBody;
                if ($nextMethod !== $currentMethod) {
                    $nextHeaders = RequestHeaderPolicy::withoutNames($nextHeaders, ['content-length', 'content-type']);
                    $nextBody = null;
                }

                ++$redirects;
                $history[] = new HttpClientAttempt(
                    $sequence,
                    $currentMethod,
                    $currentUrl,
                    HttpClientAction::REDIRECTED,
                    $response->statusCode,
                    null,
                    null,
                    $nextUrl,
                );
                $this->cleanupConsumedTarget($targetPath, $response, $history);

                $currentMethod = $nextMethod;
                $currentUrl = $nextUrl;
                $currentHeaders = $nextHeaders;
                $currentBody = $nextBody;
                continue;
            }

            if ($this->shouldRetryResponse($currentMethod, $response, $retries)) {
                $retryNumber = $retries + 1;
                $delay = $this->retryPolicy->delayMilliseconds(
                    $retryNumber,
                    $response->header('retry-after'),
                    $this->clock,
                );

                if ($this->hasTimeForNextAttempt($delay, $deadlineAt)) {
                    ++$retries;
                    $history[] = new HttpClientAttempt(
                        $sequence,
                        $currentMethod,
                        $currentUrl,
                        HttpClientAction::RETRIED,
                        $response->statusCode,
                        null,
                        $delay,
                    );
                    $this->cleanupConsumedTarget($targetPath, $response, $history);
                    $this->sleep($delay);
                    continue;
                }

                $history[] = new HttpClientAttempt(
                    $sequence,
                    $currentMethod,
                    $currentUrl,
                    HttpClientAction::FAILED,
                    $response->statusCode,
                );
                $this->cleanupConsumedTarget($targetPath, $response, $history);

                throw new HttpClientException(
                    'HTTP client deadline does not leave enough time for the configured response retry.',
                    ClientError::DEADLINE,
                    $history,
                );
            }

            $history[] = new HttpClientAttempt(
                $sequence,
                $currentMethod,
                $currentUrl,
                HttpClientAction::RETURNED,
                $response->statusCode,
            );

            return new HttpClientResult($response, $currentUrl, $history, $redirects, $retries);
        }
    }

    /**
     * Returns whether a transient transport failure may be retried.
     */
    private function shouldRetryTransport(
        string $method,
        HttpTransportException $exception,
        int $retries,
    ): bool {
        return $exception->transient
            && $retries < $this->retryPolicy->maxRetries
            && $this->retryPolicy->allowsMethod($method);
    }

    /**
     * Returns whether a completed HTTP response may be retried.
     */
    private function shouldRetryResponse(string $method, HttpResponse $response, int $retries): bool
    {
        return $retries < $this->retryPolicy->maxRetries
            && $this->retryPolicy->allowsMethod($method)
            && $this->retryPolicy->shouldRetryStatus($response->statusCode);
    }

    /**
     * @param list<HttpClientAttempt> $history
     */
    private function attemptTimeout(int $timeoutSeconds, ?float $deadlineAt, array $history): int
    {
        if ($deadlineAt === null || $timeoutSeconds < 1) {
            return $timeoutSeconds;
        }

        $remaining = $deadlineAt - $this->clock->monotonicSeconds();
        if ($remaining < 1.0) {
            throw new HttpClientException(
                'HTTP client deadline expired before another transport attempt could start.',
                ClientError::DEADLINE,
                $history,
            );
        }

        return min($timeoutSeconds, max(1, (int) floor($remaining)));
    }

    /**
     * Returns whether the global deadline leaves time for a delay and another request attempt.
     */
    private function hasTimeForNextAttempt(int $delayMilliseconds, ?float $deadlineAt): bool
    {
        if ($deadlineAt === null) {
            return true;
        }

        $remainingMilliseconds = ($deadlineAt - $this->clock->monotonicSeconds()) * 1_000;

        return $remainingMilliseconds >= $delayMilliseconds + 1_000;
    }

    /**
     * Applies a retry delay through the injected sleeper.
     */
    private function sleep(int $milliseconds): void
    {
        if ($milliseconds === 0) {
            return;
        }

        $this->sleeper->sleepMilliseconds($milliseconds);
    }

    /**
     * Removes only a target explicitly reported as created by the completed attempt.
     *
     * Transports own partial-file cleanup on failure. An unowned target appearing between
     * attempts is left intact and stops continuation rather than being unlinked.
     *
     * @param list<HttpClientAttempt> $history
     */
    private function cleanupConsumedTarget(?string $targetPath, HttpResponse $response, array $history): void
    {
        if ($targetPath === null) {
            return;
        }

        if (!$response->targetCreated) {
            if (!file_exists($targetPath) && !is_link($targetPath)) {
                return;
            }
            throw new HttpClientException(
                'Unable to continue: intermediate HTTP target is not owned by this attempt.',
                ClientError::TARGET_CLEANUP,
                $history,
            );
        }

        if (!file_exists($targetPath) && !is_link($targetPath)) {
            return;
        }
        if (@unlink($targetPath)) {
            return;
        }

        throw new HttpClientException(
            sprintf('Unable to remove intermediate HTTP target file: %s', $targetPath),
            ClientError::TARGET_CLEANUP,
            $history,
        );
    }
}
