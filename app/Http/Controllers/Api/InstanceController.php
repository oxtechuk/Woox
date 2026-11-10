<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInstanceRequest;
use App\Models\Instance;
use App\Services\Channel\EvolutionService;
use App\Services\Instance\InstanceService;
use App\Services\Webhook\WebhookService;
use App\Traits\HasApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Instance Controller — thin layer that delegates to InstanceService.
 * Mirrors Wox-Ox InstanceController pattern exactly.
 */
class InstanceController extends Controller
{
    use HasApiKey;

    public function __construct(
        private readonly InstanceService   $instanceService,
        private readonly WebhookService    $webhookService,
        private readonly EvolutionService  $evolutionService,
    ) {}

    /**
     * POST /api/instance/create
     */
    public function create(CreateInstanceRequest $request): JsonResponse
    {
        $data     = $request->validated();
        $user     = $request->user();
        $userId   = $user?->id ?? $request->header('X-User-Id') ?? $request->input('user_id');
        $tenantId = $request->header('X-Tenant-Id') ?? $request->input('tenant_id') ?? ($userId ? "tenant_{$userId}" : null);

        if ($userId) {
            $data['user_id']   = (int) $userId;
            $data['tenant_id'] = $tenantId;
        }

        $result = $this->instanceService->create($data);

        return response()->json([
            'instance' => $result['instance'],
            'hash'     => $result['hash'],
            'status'   => $result['status'],
        ], 201);
    }

    /**
     * GET /api/instance/connect/{instanceName}
     */
    public function connect(Request $request, string $instanceName): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->get('wox_instance');
        $number   = $request->query('number') ?? $request->input('number');

        $result = $this->evolutionService->connect($instance, $number);

        return response()->json($result);
    }

    /**
     * GET /api/instance/connectionState/{instanceName}
     */
    public function connectionState(Request $request, string $instanceName): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->get('wox_instance');

        $state = $this->evolutionService->getConnectionState($instance);

        return response()->json([
            'instance' => [
                'instanceName' => $instanceName,
                'state'        => $state,
            ],
        ]);
    }

    /**
     * GET /api/instance/fetchInstances
     */
    public function fetchInstances(Request $request): JsonResponse
    {
        $instanceName = $request->query('instanceName');
        $user         = $request->user();
        $isSuperAdmin = ($user && $user->isSuperAdmin()) || $request->header('X-Super-Admin') === 'true';

        $userId   = $isSuperAdmin ? null : ($user?->id ?? $request->header('X-User-Id') ?? $request->query('user_id'));
        $tenantId = $isSuperAdmin ? null : ($request->header('X-Tenant-Id') ?? $request->query('tenant_id'));

        $instances = $this->instanceService->findAll(
            instanceName: $instanceName,
            userId: $userId ? (int) $userId : null,
            tenantId: $tenantId
        );

        return response()->json($instances);
    }

    /**
     * DELETE /api/instance/delete/{instanceName}
     */
    public function delete(Request $request, string $instanceName): JsonResponse
    {
        /** @var Instance|null $instance */
        $instance = $request->get('wox_instance');
        $user     = $request->user();

        if ($instance && $instance->user_id && $user && !$user->isSuperAdmin()) {
            if ($instance->user_id !== $user->id) {
                abort(403, 'غير مصرح لك بحذف هذا الرقم');
            }
        }

        $deleted = $this->instanceService->delete($instanceName);

        return response()->json([
            'instance' => [
                'instanceName' => $instanceName,
                'status'       => $deleted ? 'removed' : 'not_found',
            ],
        ]);
    }

    /**
     * GET /api/instance/logout/{instanceName}
     */
    public function logout(Request $request, string $instanceName): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->get('wox_instance');

        $this->evolutionService->logout($instance);

        return response()->json([
            'instance' => [
                'instanceName' => $instanceName,
                'status'       => 'logout',
            ],
        ]);
    }

    /**
     * GET /api/instance/restart/{instanceName}
     */
    public function restart(Request $request, string $instanceName): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->get('wox_instance');

        $this->evolutionService->restart($instance);

        return response()->json([
            'instance' => [
                'instanceName' => $instanceName,
                'status'       => 'restarted',
            ],
        ]);
    }

    /**
     * PUT /api/instance/setPresence/{instanceName}
     */
    public function setPresence(Request $request, string $instanceName): JsonResponse
    {
        $presence = $request->validate([
            'presence' => ['required', 'in:available,unavailable,composing,recording,paused'],
        ]);

        return response()->json([
            'instance' => $instanceName,
            'presence' => $presence['presence'],
        ]);
    }
}
