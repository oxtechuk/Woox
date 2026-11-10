<?php

namespace App\Services\Instance;

use App\Enums\InstanceConnectionStatus;
use App\Models\Instance;
use App\Services\Cache\InstanceCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Visus\Cuid2\Cuid2;

/**
 * Instance Service — core business logic for instance management.
 * Mirrors Wox-Ox InstanceService pattern.
 */
class InstanceService
{
    public function __construct(
        private readonly InstanceCacheService $cache
    ) {}

    /**
     * Create a new WhatsApp instance.
     */
    public function create(array $data): array
    {
        $instanceName = $data['instanceName'];

        // Multi-tenant quota check: if user_id is provided, enforce plan instance limits
        $userId = $data['user_id'] ?? null;
        if ($userId && Schema::hasColumn('Instance', 'user_id')) {
            $user = \App\Models\User::find($userId);
            if ($user && !$user->isSuperAdmin()) {
                if ($user->isSuspended()) {
                    abort(403, 'حسابك موقوف حالياً من قبل الإدارة. يرجى التواصل مع الدعم الفني.');
                }
                $currentCount = Instance::where('user_id', $userId)->count();
                $maxAllowed   = $user->max_instances ?? 1;
                if ($currentCount >= $maxAllowed) {
                    abort(403, "لقد وصلت للحد الأقصى المسموح به لعدد أرقام الواتساب في باقتك الحالية ({$maxAllowed}). يرجى ترقية باقتك لإضافة خطوط جديدة.");
                }
            }
        }

        // Check if already exists
        $existing = Instance::where('name', $instanceName)->first();
        if ($existing) {
            return [
                'instance' => $this->formatInstance($existing),
                'hash'     => $existing->token,
                'status'   => 'existing',
            ];
        }

        $token = $data['token'] ?? (new Cuid2())->toString();

        $instance = DB::transaction(function () use ($instanceName, $token, $data, $userId) {
            $insertData = [
                'name'             => $instanceName,
                'connectionStatus' => InstanceConnectionStatus::Close,
                'token'            => $token,
                'integration'      => $data['integration'] ?? 'WHATSAPP-BAILEYS',
                'number'           => $data['number'] ?? null,
            ];

            if ($userId && Schema::hasColumn('Instance', 'user_id')) {
                $insertData['user_id'] = $userId;
            }
            if (Schema::hasColumn('Instance', 'tenant_id')) {
                $insertData['tenant_id'] = $data['tenant_id'] ?? ($userId ? "tenant_{$userId}" : null);
            }

            $inst = Instance::create($insertData);

            // Create default Setting
            \App\Models\Setting::create([
                'instanceId'      => $inst->id,
                'rejectCall'      => $data['rejectCall'] ?? false,
                'msgCall'         => $data['msgCall'] ?? null,
                'groupsIgnore'    => $data['groupsIgnore'] ?? false,
                'alwaysOnline'    => $data['alwaysOnline'] ?? false,
                'readMessages'    => $data['readMessages'] ?? false,
                'readStatus'      => $data['readStatus'] ?? false,
                'syncFullHistory' => $data['syncFullHistory'] ?? false,
            ]);

            // Create Webhook if provided
            if (!empty($data['webhook']['url'])) {
                \App\Models\Webhook::create([
                    'instanceId'      => $inst->id,
                    'url'             => $data['webhook']['url'],
                    'enabled'         => true,
                    'events'          => $data['webhook']['events'] ?? $this->defaultWebhookEvents(),
                    'webhookByEvents' => $data['webhook']['webhookByEvents'] ?? false,
                    'webhookBase64'   => $data['webhook']['webhookBase64'] ?? false,
                ]);
            }

            return $inst;
        });

        Log::info('Instance created', ['name' => $instanceName, 'id' => $instance->id]);

        return [
            'instance' => $this->formatInstance($instance),
            'hash'     => $token,
            'status'   => 'created',
        ];
    }

    /**
     * Find all instances (scoped to user/tenant or global for super admin) or single instance.
     */
    public function findAll(?string $instanceName = null, ?int $userId = null, ?string $tenantId = null): array
    {
        $query = Instance::with(['setting', 'webhook']);

        if ($instanceName) {
            $query->where('name', $instanceName);
        }

        if ($userId && Schema::hasColumn('Instance', 'user_id')) {
            $query->where('user_id', $userId);
        } elseif ($tenantId && Schema::hasColumn('Instance', 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->map(fn($inst) => $this->formatInstance($inst))->toArray();
    }

    /**
     * Find a single instance by name.
     */
    public function findOne(string $instanceName): ?Instance
    {
        return $this->cache->getInstance($instanceName);
    }

    /**
     * Delete an instance and all its data.
     */
    public function delete(string $instanceName): bool
    {
        $instance = Instance::where('name', $instanceName)->first();

        if (!$instance) {
            return false;
        }

        $instance->delete(); // Cascades to all related tables
        $this->cache->invalidate($instanceName);

        Log::info('Instance deleted', ['name' => $instanceName]);
        return true;
    }

    /**
     * Update connection status.
     */
    public function updateConnectionStatus(string $instanceName, InstanceConnectionStatus $status): void
    {
        Instance::where('name', $instanceName)->update([
            'connectionStatus' => $status,
            'updatedAt'        => now(),
        ]);

        $this->cache->invalidate($instanceName);
    }

    /**
     * Format instance for API response — matches Wox-Ox response structure.
     */
    public function formatInstance(Instance $instance): array
    {
        return [
            'instanceName'     => $instance->name,
            'instanceId'       => $instance->id,
            'status'           => $instance->connectionStatus->value,
            'integration'      => $instance->integration,
            'owner'            => $instance->ownerJid,
            'profileName'      => $instance->profileName,
            'profilePicUrl'    => $instance->profilePicUrl,
            'profileStatus'    => null,
            'serverUrl'        => config('evolution.server.url'),
            'apikey'           => $instance->token,
            'token'            => $instance->token,
            'number'           => $instance->number,
            'businessId'       => $instance->businessId,
            'clientName'       => $instance->clientName,
        ];
    }

    private function defaultWebhookEvents(): array
    {
        $events = config('evolution.webhook.events', []);
        return array_keys(array_filter($events));
    }
}
