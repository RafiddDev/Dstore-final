<?php

namespace App\DStore\Http\Middleware;

use App\DStore\Security\Exceptions\ReplayDetectedException;
use App\DStore\Security\Exceptions\SecurityException;
use App\DStore\Security\Exceptions\UnauthorizedNodeException;
use App\DStore\Security\MessageAuthenticator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any node-to-node request that is not signed by an approved peer.
 */
class VerifyNodeSignature
{
    public const ENVELOPE_HEADER = 'X-DStore-Envelope';

    public function __construct(private readonly MessageAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $envelope = $this->extract($request);

        if ($envelope === null) {
            return $this->deny('A signed envelope is required.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $verified = $this->authenticator->authenticate($envelope);
        } catch (UnauthorizedNodeException $exception) {
            return $this->deny($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (ReplayDetectedException $exception) {
            return $this->deny($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SecurityException $exception) {
            return $this->deny($exception->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('dstore_envelope', $verified);

        return $next($request);
    }

    private function extract(Request $request): ?array
    {
        $header = $request->header(self::ENVELOPE_HEADER);

        if (is_string($header) && $header !== '') {
            $decoded = json_decode(base64_decode($header, true) ?: '', true);

            return is_array($decoded) ? $decoded : null;
        }

        $body = $request->json()->all();

        if (isset($body['envelope']) && is_array($body['envelope'])) {
            return $body['envelope'];
        }

        return null;
    }

    private function deny(string $reason, int $status): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => $reason,
        ], $status);
    }
}
