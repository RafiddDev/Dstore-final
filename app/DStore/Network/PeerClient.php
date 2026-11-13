<?php

namespace App\DStore\Network;

use App\DStore\Http\Middleware\VerifyNodeSignature;
use App\DStore\Security\MessageAuthenticator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to peers over HTTP with signed envelopes, timeouts and retries.
 *
 * Every attempt is signed with a fresh envelope id. If a request times out
 * after the peer already processed it, the retry must not look like a replay
 * of the first attempt, so the envelope is rebuilt per attempt while the
 * payload — and therefore the event id inside it — stays identical.
 */
final class PeerClient
{
    public function __construct(
        private readonly MessageAuthenticator $authenticator,
        private readonly int $timeout = 10,
        private readonly int $maxAttempts = 3,
        private readonly int $retryBaseDelay = 2,
        private readonly int $retryMaxDelay = 60,
    ) {}

    public function get(PeerEndpoint $peer, string $path, array $query = []): PeerResponse
    {
        return $this->send($peer, 'GET', $path, [], $query);
    }

    public function post(PeerEndpoint $peer, string $path, array $payload = []): PeerResponse
    {
        return $this->send($peer, 'POST', $path, $payload);
    }

    /**
     * Sends a body that is not wrapped in a signed envelope.
     *
     * Only the enrolment request needs this: it reaches a peer that does not
     * trust this node yet, so it carries the bootstrap token and a proof of
     * possession instead of an envelope signature.
     */
    public function postRaw(PeerEndpoint $peer, string $path, array $payload = []): PeerResponse
    {
        $url = $peer->endpoint($path);
        $started = microtime(true);
        $lastError = 'unknown error';
        $lastStatus = 0;
        $lastBody = [];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->send('POST', $url, ['json' => $payload]);
            } catch (ConnectionException $exception) {
                $lastError = $exception->getMessage();
                $lastStatus = 0;
                $lastBody = [];

                if ($attempt < $this->maxAttempts) {
                    sleep($this->backoff($attempt));
                }

                continue;
            }

            $lastStatus = $response->status();
            $decoded = $response->json();
            $lastBody = is_array($decoded) ? $decoded : [];

            if ($response->successful()) {
                return PeerResponse::success($lastStatus, $lastBody, $attempt, $this->elapsed($started));
            }

            $lastError = (string) ($lastBody['error'] ?? ($response->body() ?: 'HTTP '.$lastStatus));

            if (! $this->isRetryable($lastStatus)) {
                return PeerResponse::failure($lastStatus, $lastBody, $lastError, $attempt, $this->elapsed($started));
            }

            if ($attempt < $this->maxAttempts) {
                sleep($this->backoff($attempt));
            }
        }

        return PeerResponse::failure(
            $lastStatus,
            $lastBody,
            $lastError,
            $this->maxAttempts,
            $this->elapsed($started),
        );
    }

    public function send(
        PeerEndpoint $peer,
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
    ): PeerResponse {
        $url = $peer->endpoint($path);

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        $started = microtime(true);
        $lastError = 'unknown error';
        $lastStatus = 0;
        $lastBody = [];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $envelope = $this->authenticator->sign($payload);

            try {
                $response = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->withHeaders([
                        VerifyNodeSignature::ENVELOPE_HEADER => base64_encode(json_encode($envelope->toArray())),
                    ])
                    ->send($method, $url, ['json' => ['envelope' => $envelope->toArray()]]);
            } catch (ConnectionException $exception) {
                $lastError = $exception->getMessage();
                $lastStatus = 0;
                $lastBody = [];

                if ($attempt < $this->maxAttempts) {
                    sleep($this->backoff($attempt));
                }

                continue;
            } catch (\Throwable $exception) {
                return PeerResponse::failure(
                    0,
                    [],
                    $exception->getMessage(),
                    $attempt,
                    $this->elapsed($started),
                );
            }

            $lastStatus = $response->status();
            $decoded = $response->json();
            $lastBody = is_array($decoded) ? $decoded : [];

            if ($response->successful()) {
                return PeerResponse::success(
                    $lastStatus,
                    $lastBody,
                    $attempt,
                    $this->elapsed($started),
                );
            }

            $lastError = (string) ($lastBody['error'] ?? ($response->body() ?: 'HTTP '.$lastStatus));

            if (! $this->isRetryable($lastStatus)) {
                return PeerResponse::failure(
                    $lastStatus,
                    $lastBody,
                    $lastError,
                    $attempt,
                    $this->elapsed($started),
                );
            }

            if ($attempt < $this->maxAttempts) {
                sleep($this->backoff($attempt));
            }
        }

        return PeerResponse::failure(
            $lastStatus,
            $lastBody,
            $lastError,
            $this->maxAttempts,
            $this->elapsed($started),
        );
    }

    private function isRetryable(int $status): bool
    {
        if ($status === 0) {
            return true;
        }

        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->retryBaseDelay * (2 ** ($attempt - 1));

        return (int) min($delay, $this->retryMaxDelay);
    }

    private function elapsed(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
