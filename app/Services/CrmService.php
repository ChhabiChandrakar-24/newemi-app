<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CrmLead;
use App\Models\CrmProject;
use App\Models\CrmVisit;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrmService
{
    public function generateLeadCode(): string
    {
        $maxId = (int) CrmLead::withoutGlobalScopes()->max('id') + 1;
        return 'LEAD-' . date('Ym') . '-' . str_pad((string) $maxId, 5, '0', STR_PAD_LEFT);
    }

    public function generateVisitCode(): string
    {
        $maxId = (int) CrmVisit::withoutGlobalScopes()->max('id') + 1;
        return 'VST-' . date('Ym') . '-' . str_pad((string) $maxId, 5, '0', STR_PAD_LEFT);
    }

    public function generateProjectCode(): string
    {
        $maxId = (int) CrmProject::withoutGlobalScopes()->max('id') + 1;
        return 'PRJ-' . date('Ym') . '-' . str_pad((string) $maxId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Store uploaded photos or base64 data URLs for a visit.
     *
     * @param array $files
     * @return array<string> Relative storage paths
     */
    public function storeVisitPhotos(array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $path = $file->store('crm-visits/' . date('Y/m'), 'public');
                $paths[] = $path;
            } elseif (is_string($file) && str_starts_with($file, 'data:image')) {
                // Base64 data URL from live webcam/camera capture
                if (preg_match('/^data:image\/(\w+);base64,/', $file, $type)) {
                    $data = substr($file, strpos($file, ',') + 1);
                    $type = strtolower($type[1]);
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $filename = 'crm-visits/' . date('Y/m') . '/' . Str::uuid() . '.' . $type;
                        Storage::disk('public')->put($filename, $data);
                        $paths[] = $filename;
                    }
                }
            } elseif (is_string($file) && filled($file)) {
                $paths[] = $file;
            }
        }

        return $paths;
    }

    /**
     * Convert a won Lead into an active Customer record.
     */
    public function convertLeadToCustomer(CrmLead $lead, User $actor): Customer
    {
        return DB::transaction(function () use ($lead, $actor) {
            if ($lead->converted_customer_id && $lead->convertedCustomer) {
                return $lead->convertedCustomer;
            }

            $maxCustId = (int) Customer::withoutGlobalScopes()->max('id') + 1;
            $customerCode = 'CUST-' . str_pad((string) $maxCustId, 5, '0', STR_PAD_LEFT);

            $customer = Customer::create([
                'company_id' => $lead->company_id,
                'customer_code' => $customerCode,
                'full_name' => $lead->customer_name,
                'mobile_number' => $lead->phone,
                'alternate_mobile_number' => $lead->alternate_phone,
                'email' => $lead->email,
                'address_line_1' => $lead->address,
                'city' => $lead->city,
                'state' => $lead->state,
                'status' => 'active',
                'consent_given' => true,
                'consent_given_at' => now(),
                'notes' => 'Converted from CRM Lead: ' . $lead->lead_code . ($lead->shop_name ? " (Shop: {$lead->shop_name})" : ''),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $lead->update([
                'status' => 'won',
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
                'updated_by' => $actor->id,
            ]);

            return $customer;
        });
    }
}
