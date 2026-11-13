<?php

namespace Tests\Feature\Network;

use App\DStore\Http\Middleware\VerifyNodeSignature;
use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerEndpoint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PeerClientTest extends TestCase
{
    private function client(int $maxAttempts = 3): PeerClient
    {
        return new PeerClient(
            $this->app->make(\App\DStore\Security\MessageAuthenticator::class),
            5,
            $maxAttempts,
            0,
            0,
        );
    }

    private function peer(): PeerEndpoint
    {
        return new PeerEndpoint('uae', 'http://127.0.0.1:8002');
    }

    public function test_a_successful_call_returns_the_decoded_body(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'node_id' => 'uae'], 200)]);

        $response = $this->client()->get($this->peer(), '/api/dstore/v1/health');

        $this->assertTrue($response->successful);
        $this->assertSame('uae', $response->body('node_id'));
        $this->assertSame(1, $response->attempts);
    }

    public function test_every_request_carries_a_signed_envelope(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->client()->get($this->peer(), '/api/dstore/v1/health');

        Http::assertSent(function (Request $request): bool {
            $header = $request->header(VerifyNodeSignature::ENVELOPE_HEADER);

            if (! is_array($header) || $header === []) {
                return false;
            }

            $envelope = json_decode((string) base64_decode($header[0], true), true);

            return is_array($envelope)
                && isset($envelope['signature'])
                && $envelope['signature'] !== ''
                && $envelope['origin_node'] === 'test-node';
        });
    }

    public function test_each_retry_uses_a_fresh_envelope_id(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->client()->get($this->peer(), '/api/dstore/v1/health');

        $ids = [];

        Http::assertSent(function (Request $request) use (&$ids): bool {
            $header = $request->header(VerifyNodeSignature::ENVELOPE_HEADER);
            $envelope = json_decode((string) base64_decode((string) ($header[0] ?? ''), true), true);
            $ids[] = $envelope['message_id'] ?? null;

            return true;
        });

        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids));
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $response = $this->client()->get($this->peer(), '/api/dstore/v1/health');

        $this->assertFalse($response->successful);
        $this->assertSame(500, $response->status);
        $this->assertSame(3, $response->attempts);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 403)]);

        $response = $this->client()->get($this->peer(), '/api/dstore/v1/health');

        $this->assertFalse($response->successful);
        $this->assertSame(403, $response->status);
        $this->assertSame(1, $response->attempts);
    }

    public function test_an_unreachable_peer_is_reported_as_a_failure(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $response = $this->client()->get($this->peer(), '/api/dstore/v1/health');

        $this->assertFalse($response->successful);
        $this->assertTrue($response->unreachable());
        $this->assertSame(0, $response->status);
        $this->assertStringContainsString('Connection refused', (string) $response->error);
    }
}
