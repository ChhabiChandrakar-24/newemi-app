<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class CrmVisit extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'lead_id',
        'visit_code',
        'sales_person_id',
        'shop_name',
        'contact_person',
        'phone',
        'visit_date',
        'purpose',
        'outcome',
        'notes',
        'latitude',
        'longitude',
        'accuracy_meters',
        'location_address',
        'photos',
        'next_action',
        'next_followup_at',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['photo_urls'];

    protected function casts(): array
    {
        return [
            'visit_date' => 'datetime',
            'next_followup_at' => 'datetime',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'accuracy_meters' => 'float',
            'photos' => 'array',
        ];
    }

    public function getPhotoUrlsAttribute(): array
    {
        $photos = $this->photos ?? [];
        if (!is_array($photos)) {
            return [];
        }

        return array_map(function ($path) {
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
                return $path;
            }
            return url(Storage::url($path));
        }, $photos);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }
}
