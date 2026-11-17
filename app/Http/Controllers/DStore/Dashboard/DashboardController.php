<?php

namespace App\Http\Controllers\DStore\Dashboard;

use App\DStore\Dashboard\DashboardService;
use App\DStore\Monitoring\HeartbeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The read-only dashboard.
 *
 * The HTML pages are rendered from the same data the JSON endpoints serve, so
 * the auto-refresh in the browser never shows anything the server would not
 * show on a fresh page load.
 */
class DashboardController extends Controller
{
    public function index(DashboardService $dashboard)
    {
        $overview = $dashboard->overview();

        return view('dashboard.overview', [
            'overview' => $overview,
            'selfId' => $dashboard->self()['node_id'],
            'switchNodes' => $overview['nodes'] ?? [],
        ]);
    }

    public function node(string $node, DashboardService $dashboard)
    {
        $data = $dashboard->node($node);

        abort_if($data === null, 404, "Node {$node} is not known to this node.");

        $isSelf = $dashboard->isSelf($node);
        $overview = $dashboard->overview();

        return view('dashboard.node', [
            'node' => $data,
            'isSelf' => $isSelf,
            'selfId' => $overview['node']['node_id'],
            'switchNodes' => $overview['nodes'] ?? [],
            'backups' => $isSelf ? $overview['backups'] : [],
            'conflicts' => $overview['conflicts'],
            'recovery' => $isSelf ? $overview['recovery'] : null,
        ]);
    }

    public function data(DashboardService $dashboard): JsonResponse
    {
        return new JsonResponse($dashboard->overview());
    }

    public function nodeData(string $node, DashboardService $dashboard): JsonResponse
    {
        $data = $dashboard->node($node);

        if ($data === null) {
            return new JsonResponse(['ok' => false, 'error' => "Node {$node} is not known."], 404);
        }

        return new JsonResponse(['ok' => true, 'node' => $data]);
    }

    /**
     * Sends one signed heartbeat to the peer and answers with the refreshed
     * status. This is the button an operator presses after restarting a node;
     * waiting for the monitor would otherwise take a full interval.
     */
    public function probe(string $node, DashboardService $dashboard, HeartbeatService $heartbeats): JsonResponse
    {
        if ($dashboard->isSelf($node)) {
            return new JsonResponse(['ok' => false, 'error' => 'This is the local node; it is always online.'], 422);
        }

        if ($dashboard->node($node) === null) {
            return new JsonResponse(['ok' => false, 'error' => "Node {$node} is not known."], 404);
        }

        try {
            $result = $heartbeats->beat($node);
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'error' => $exception->getMessage()], 502);
        }

        return new JsonResponse([
            'ok' => $result->reachable,
            'heartbeat' => $result->toArray(),
            'node' => $dashboard->node($node),
        ], $result->reachable ? 200 : 503);
    }
}
