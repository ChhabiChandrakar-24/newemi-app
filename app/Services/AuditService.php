<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'remember_token', 'token',
        'enrollment_token', 'device_credential', 'token_hash',
        'secret', 'webhook_secret', 'encrypted_secret', 'encrypted_webhook_secret', 'config',
    ];

    public function __construct(private readonly Request $request) {}

    public function record(
        string $action,
        Model $entity,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $remarks = null,
    ): AuditLog {
        /** @var User|null $actor */
        $actor = $this->request->user();

        $companyId = $entity instanceof \App\Models\Company ? $entity->getKey() : ($entity->getAttribute('company_id') ?? $actor?->company_id);

        return AuditLog::query()->create([
            'company_id' => $companyId,
            'actor_user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => (string) $entity->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'remarks' => $remarks,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        return $values === null ? null : collect($values)->except(self::SENSITIVE_KEYS)->all();
    }
}
