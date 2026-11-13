<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Security\Envelope;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Base class for the signed node-to-node endpoints.
 *
 * The middleware has already verified the envelope and stored it on the
 * request. A signed endpoint must read its input from that verified payload
 * and never from the raw request body, which an attacker controls.
 */
abstract class DStoreController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $envelope = $request->attributes->get('dstore_envelope');

        if ($envelope instanceof Envelope) {
            return $envelope->payload;
        }

        if (is_array($envelope)) {
            return (array) ($envelope['payload'] ?? []);
        }

        return [];
    }
}
