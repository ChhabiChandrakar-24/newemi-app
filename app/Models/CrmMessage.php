<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmMessage extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'campaign_name',
        'channel',
        'gateway_provider',
        'recipient_count',
        'recipients',
        'message_body',
        'status',
        'response_payload',
        'created_by',
    ];

    protected $appends = ['template_body', 'gateway_used', 'recipient_type'];

    protected function casts(): array
    {
        return [
            'recipient_count' => 'integer',
            'recipients' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function getTemplateBodyAttribute(): string
    {
        return $this->message_body ?? '';
    }

    public function getGatewayUsedAttribute(): string
    {
        return $this->gateway_provider ?? 'simulation';
    }

    public function getRecipientTypeAttribute(): string
    {
        return $this->response_payload['recipient_type'] ?? ($this->recipient_count === 1 ? 'individual' : 'broadcast');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
