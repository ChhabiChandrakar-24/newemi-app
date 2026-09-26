<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AndroidAgentMetadataController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceAgentController;
use App\Http\Controllers\Api\V1\DeviceCommandController;
use App\Http\Controllers\Api\V1\DataRetentionPolicyController;
use App\Http\Controllers\Api\V1\DeviceConsentController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceEnrollmentController;
use App\Http\Controllers\Api\V1\DeviceNotificationController;
use App\Http\Controllers\Api\V1\DeviceReleaseController;
use App\Http\Controllers\Api\V1\DeviceEventController;
use App\Http\Controllers\Api\V1\DeviceLocationController;
use App\Http\Controllers\Api\V1\DeviceManagementSettingsController;
use App\Http\Controllers\Api\V1\DevicePushTokenController;
use App\Http\Controllers\Api\V1\DeviceAccountController;
use App\Http\Controllers\Api\V1\EmiAccountController;
use App\Http\Controllers\Api\V1\LockPolicyController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\OnlinePaymentController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\PlatformPlanController;
use App\Http\Controllers\Api\V1\PlatformRechargeController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StatementController;
use App\Http\Controllers\Api\V1\PublicOnboardingController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\CompanyUserController;
use App\Http\Controllers\Api\V1\CrmLeadController;
use App\Http\Controllers\Api\V1\CrmVisitController;
use App\Http\Controllers\Api\V1\CrmProjectController;
use App\Http\Controllers\Api\V1\CrmMessageController;
use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('health', [OperationsController::class, 'publicHealth'])->middleware('throttle:60,1');
Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)->middleware('throttle:120,1');

use App\Http\Controllers\Api\V1\AgentDownloadController;
use App\Http\Controllers\Api\V1\AgentBrandingController;

Route::get('agent-downloads', [AgentDownloadController::class, 'index']);
Route::get('agent-branding', [AgentBrandingController::class, 'show']);
Route::get('downloads/android', [AgentDownloadController::class, 'downloadAndroid']);
Route::get('downloads/windows', [AgentDownloadController::class, 'downloadWindows']);
Route::get('downloads/ios', [AgentDownloadController::class, 'downloadIos']);

// Section 15: Device API (Canonical direct endpoints)
Route::post('device/consent/preview', [DeviceConsentController::class, 'preview'])->middleware('throttle:device-consent');
Route::post('device/consent', [DeviceConsentController::class, 'accept'])->middleware('throttle:device-consent');
Route::post('device/enroll', [DeviceEnrollmentController::class, 'claim'])->middleware('throttle:device-enrollment');
Route::post('device/events', [DeviceEventController::class, 'report'])->middleware(['device.auth', 'throttle:device-commands']);
Route::post('device/re-enroll', [DeviceEnrollmentController::class, 'reEnroll'])->middleware(['device.auth', 'throttle:device-commands']);
Route::post('device/{device}/heartbeat', [DeviceAgentController::class, 'heartbeat'])->middleware(['device.auth', 'throttle:device-heartbeat']);
Route::post('device/heartbeat', [DeviceAgentController::class, 'heartbeat'])->middleware(['device.auth', 'throttle:device-heartbeat']);

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('device/{device}', [DeviceController::class, 'show'])->middleware('permission:devices.view');
    Route::get('device/{device}/status', [DeviceController::class, 'status'])->middleware('permission:devices.view');
    Route::post('device/{device}/release', [DeviceController::class, 'release'])->middleware('permission:devices.release');
    Route::get('device/{device}/events', [DeviceEventController::class, 'index'])->middleware('permission:devices.events.view');
    Route::get('device/{device}/enrollment', [DeviceEnrollmentController::class, 'latestForDevice'])->middleware('permission:devices.enroll');

    // Section 16: EMI API (Canonical direct endpoints)
    Route::post('emi', [EmiAccountController::class, 'store'])->middleware('permission:emi.create');
    Route::get('emi/{emiAccount}', [EmiAccountController::class, 'show'])->middleware('permission:emi.view');
    Route::get('emi/{emiAccount}/status', [EmiAccountController::class, 'summary'])->middleware('permission:emi.view');
    Route::post('emi/{emiAccount}/payment', [PaymentController::class, 'store'])->middleware('permission:payments.create');
    Route::post('emi/{emiAccount}/complete', [EmiAccountController::class, 'complete'])->middleware('permission:emi.complete');
    Route::get('emi/{emiAccount}/payments', [PaymentController::class, 'forAccount'])->middleware('permission:payments.view');
});

Route::prefix('v1')->group(function (): void {
    Route::get('agent-downloads', [AgentDownloadController::class, 'index']);
    Route::get('agent-branding', [AgentBrandingController::class, 'show']);
    Route::get('downloads/android', [AgentDownloadController::class, 'downloadAndroid']);
    Route::get('downloads/windows', [AgentDownloadController::class, 'downloadWindows']);
    Route::get('downloads/ios', [AgentDownloadController::class, 'downloadIos']);
    Route::post('device-enrollment/claim', [DeviceEnrollmentController::class, 'claim'])->middleware('throttle:device-enrollment');
    Route::post('device/enroll', [DeviceEnrollmentController::class, 'claim'])->middleware('throttle:device-enrollment');
    Route::post('device/consent/preview', [DeviceConsentController::class, 'preview'])->middleware('throttle:device-consent');
    Route::post('device/consent', [DeviceConsentController::class, 'accept'])->middleware('throttle:device-consent');
    Route::post('device/consent/withdraw', [DeviceConsentController::class, 'withdraw'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::post('device/events', [DeviceEventController::class, 'report'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::post('device/re-enroll', [DeviceEnrollmentController::class, 'reEnroll'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::post('device/heartbeat', [DeviceAgentController::class, 'heartbeat'])->middleware(['device.auth', 'throttle:device-heartbeat']);
    Route::post('device/push-token', [DevicePushTokenController::class, 'store'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::delete('device/push-token', [DevicePushTokenController::class, 'destroy'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::post('device/location', [DeviceLocationController::class, 'report'])->middleware(['device.auth', 'throttle:device-location']);
    Route::get('device/account', [DeviceAccountController::class, 'show'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::post('device/payment/order', [OnlinePaymentController::class, 'createDeviceOrder'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::post('device/payment/verify', [OnlinePaymentController::class, 'verifyDevicePayment'])->middleware(['device.auth', 'throttle:device-commands']);
    Route::middleware(['device.auth', 'throttle:device-commands'])->prefix('device/commands')->group(function (): void {
        Route::get('next', [DeviceCommandController::class, 'pull']);
        Route::post('{command}/received', [DeviceCommandController::class, 'received']);
        Route::post('{command}/acknowledge', [DeviceCommandController::class, 'acknowledge']);
        Route::post('{command}/result', [DeviceCommandController::class, 'result']);
    });
    Route::get('public/plans', [PublicOnboardingController::class, 'plans']);
    Route::post('public/register-company', [PublicOnboardingController::class, 'register']);
    Route::post('public/verify-registration-payment', [PublicOnboardingController::class, 'verifyPayment']);

    Route::prefix('auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('send-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:login');
        Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'platform.admin'])->prefix('platform')->group(function (): void {
        Route::get('companies', [CompanyController::class, 'index']);
        Route::post('companies', [CompanyController::class, 'store']);
        Route::get('companies/{company}', [CompanyController::class, 'show']);
        Route::put('companies/{company}', [CompanyController::class, 'update']);
        Route::patch('companies/{company}/status', [CompanyController::class, 'status']);
        Route::delete('companies/{company}', [CompanyController::class, 'destroy']);
        Route::post('companies/{company}/restore', [CompanyController::class, 'restore']);

        Route::get('plans', [PlatformPlanController::class, 'index']);
        Route::post('plans', [PlatformPlanController::class, 'store']);
        Route::get('plans/{plan}', [PlatformPlanController::class, 'show']);
        Route::put('plans/{plan}', [PlatformPlanController::class, 'update']);
        Route::delete('plans/{plan}', [PlatformPlanController::class, 'destroy']);

        Route::get('recharges', [PlatformRechargeController::class, 'index']);
        Route::patch('recharges/{recharge}/approve', [PlatformRechargeController::class, 'approve']);
        Route::patch('recharges/{recharge}/reject', [PlatformRechargeController::class, 'reject']);
        Route::post('recharges/{recharge}/refund', [PlatformRechargeController::class, 'refund']);
        Route::post('recharges/{recharge}/approve-transfer', [PlatformRechargeController::class, 'approveTransfer']);
        Route::post('recharges/{recharge}/reject-transfer', [PlatformRechargeController::class, 'rejectTransfer']);
        Route::post('companies/{company}/grant-subscription', [PlatformRechargeController::class, 'grant']);

        // Dedicated Razorpay Webhooks Module (System Admin only)
        Route::get('webhooks', [\App\Http\Controllers\Api\V1\PlatformWebhookLogController::class, 'index']);
        Route::get('webhooks/{webhookLog}', [\App\Http\Controllers\Api\V1\PlatformWebhookLogController::class, 'show']);

        // Platform Promo Codes / Discount Coupons (Platform Admin only)
        Route::get('promo-codes', [\App\Http\Controllers\Api\V1\PlatformPromoCodeController::class, 'index']);
        Route::post('promo-codes', [\App\Http\Controllers\Api\V1\PlatformPromoCodeController::class, 'store']);
        Route::get('promo-codes/{promoCode}', [\App\Http\Controllers\Api\V1\PlatformPromoCodeController::class, 'show']);
        Route::put('promo-codes/{promoCode}', [\App\Http\Controllers\Api\V1\PlatformPromoCodeController::class, 'update']);
        Route::delete('promo-codes/{promoCode}', [\App\Http\Controllers\Api\V1\PlatformPromoCodeController::class, 'destroy']);
        Route::patch('promo-codes/{promoCode}/toggle', [\App\Http\Controllers\Api\V1\PlatformPromoCodeController::class, 'toggle']);

        // EMI Testing and Bypass (Super Admin only)
        Route::post('emi/schedules/{schedule}/bypass', [\App\Http\Controllers\Api\V1\EmiBypassController::class, 'bypass'])->middleware('role:super-admin');
        Route::post('emi/schedules/{schedule}/delay', [\App\Http\Controllers\Api\V1\EmiBypassController::class, 'delay'])->middleware('role:super-admin');
    });

    Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
        Route::get('android-agent', [AndroidAgentMetadataController::class, 'show'])->middleware('permission:devices.view');
        Route::put('android-agent', [AndroidAgentMetadataController::class, 'update'])->middleware('permission:settings.update');
        Route::get('dashboard', DashboardController::class);
        Route::get('dashboard/analytics', DashboardController::class)->middleware('permission:reports.view');
        Route::get('reports/{type}', [ReportController::class, 'show'])->middleware('permission:reports.view');
        Route::post('reports/generate', [ReportController::class, 'generate'])->middleware('permission:reports.export');
        Route::get('generated-reports', [ReportController::class, 'generated'])->middleware('permission:reports.view');
        Route::get('generated-reports/{report}', [ReportController::class, 'generatedShow'])->middleware('permission:reports.view');
        Route::get('generated-reports/{report}/download', [ReportController::class, 'download'])->middleware('permission:reports.export');
        Route::get('scheduled-reports', [ReportController::class, 'schedules'])->middleware('permission:reports.schedule');
        Route::post('scheduled-reports', [ReportController::class, 'scheduleStore'])->middleware('permission:reports.schedule');
        Route::put('scheduled-reports/{report}', [ReportController::class, 'scheduleUpdate'])->middleware('permission:reports.schedule');
        Route::get('customers/{customer}/statement', [StatementController::class, 'customer'])->middleware('permission:reports.financial');
        Route::get('emi-accounts/{emiAccount}/statement', [StatementController::class, 'account'])->middleware('permission:reports.financial');
        Route::get('devices/{device}/history-report', [StatementController::class, 'device'])->middleware('permission:reports.devices');
        Route::get('payments/{payment}/receipt/pdf', [StatementController::class, 'receipt'])->middleware('permission:payments.view');
        Route::get('device-commands', [OperationsController::class, 'commands'])->middleware('permission:devices.commands.view');
        Route::get('alerts', [OperationsController::class, 'alerts'])->middleware('permission:alerts.view');
        Route::patch('alerts/{event}/acknowledge', [OperationsController::class, 'acknowledgeAlert'])->middleware('permission:alerts.manage');
        Route::post('alerts/bulk-acknowledge', [OperationsController::class, 'bulkAcknowledgeAlerts'])->middleware('permission:alerts.manage');
        Route::get('audit-logs', [OperationsController::class, 'audit'])->middleware('permission:audit.view');
        Route::get('system/health', [OperationsController::class, 'health'])->middleware('permission:settings.view');
        Route::get('system/integration-status', [SettingsController::class, 'integrations'])->middleware('permission:settings.integrations.view');
        Route::get('settings/integrations', [SettingsController::class, 'integrations'])->middleware('permission:settings.integrations.view');
        Route::get('settings/device-management', [DeviceManagementSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::put('settings/device-management', [DeviceManagementSettingsController::class, 'update'])->middleware('permission:settings.update');
        Route::post('settings/device-management/reset', [DeviceManagementSettingsController::class, 'reset'])->middleware('permission:settings.update');
        Route::get('settings/device-management/history', [DeviceManagementSettingsController::class, 'history'])->middleware('permission:audit.view');
        Route::put('agent-downloads', [AgentDownloadController::class, 'update'])->middleware('permission:settings.update');
        Route::post('agent-downloads/upload', [AgentDownloadController::class, 'upload'])->middleware('permission:settings.update');
        Route::put('agent-branding', [AgentBrandingController::class, 'update'])->middleware('permission:settings.update');
        Route::post('agent-branding/upload', [AgentBrandingController::class, 'uploadAsset'])->middleware('permission:settings.update');
        Route::get('setup/status', [SettingsController::class, 'setupStatus'])->middleware('role:super-admin');
        Route::post('setup/complete', [SettingsController::class, 'setupComplete'])->middleware('role:super-admin');
        Route::post('settings/integrations/{provider}/test', [SettingsController::class, 'test'])->middleware('permission:settings.integrations.update');
        Route::get('settings/{category}', [SettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::put('settings/{category}', [SettingsController::class, 'update'])->middleware('permission:settings.update');
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->middleware('permission:users.update');
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        Route::post('users/{user}/restore', [UserController::class, 'restore'])->middleware('permission:users.update');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view');

        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::get('customers/{customer}/summary', [CustomerController::class, 'summary'])->middleware('permission:customers.view');
        Route::get('customers/{customer}/history', [CustomerController::class, 'history'])->middleware('permission:customers.view');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
        Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])->middleware('permission:customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->middleware('permission:customers.restore');
        Route::get('customers/{customer}/emi-accounts', [EmiAccountController::class, 'forCustomer'])->middleware('permission:emi.view');
        Route::get('customers/{customer}/payments', [PaymentController::class, 'forCustomer'])->middleware('permission:payments.view');
        Route::get('customers/{customer}/devices', [DeviceController::class, 'forCustomer'])->middleware('permission:devices.view');

        Route::get('emi-accounts', [EmiAccountController::class, 'index'])->middleware('permission:emi.view');
        Route::get('emi-accounts/{emiAccount}/summary', [EmiAccountController::class, 'summary'])->middleware('permission:emi.view');
        Route::get('emi-accounts/{emiAccount}/schedule', [EmiAccountController::class, 'schedule'])->middleware('permission:emi.view');
        Route::get('emi-accounts/{emiAccount}', [EmiAccountController::class, 'show'])->middleware('permission:emi.view');
        Route::post('emi-accounts', [EmiAccountController::class, 'store'])->middleware('permission:emi.create');
        Route::put('emi-accounts/{emiAccount}', [EmiAccountController::class, 'update'])->middleware('permission:emi.update');
        Route::patch('emi-accounts/{emiAccount}/status', [EmiAccountController::class, 'updateStatus'])->middleware('permission:emi.close');
        Route::get('emi-accounts/{emiAccount}/payments', [PaymentController::class, 'forAccount'])->middleware('permission:payments.view');
        Route::post('emi-accounts/{emiAccount}/settlement-preview', [PaymentController::class, 'settlementPreview'])->middleware('permission:payments.settlement');
        Route::post('emi-accounts/{emiAccount}/settle', [PaymentController::class, 'settle'])->middleware('permission:payments.settlement');
        Route::get('emi-accounts/{emiAccount}/devices', [DeviceController::class, 'forAccount'])->middleware('permission:devices.view');

        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('permission:payments.view');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:payments.create');
        Route::post('payments/online/order', [OnlinePaymentController::class, 'createWebOrder'])->middleware('permission:payments.create');
        Route::post('payments/online/verify', [OnlinePaymentController::class, 'verifyWebPayment'])->middleware('permission:payments.create');
        Route::patch('payments/{payment}/verify', [PaymentController::class, 'verify'])->middleware('permission:payments.verify');
        Route::patch('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->middleware('permission:payments.update');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:payments.reverse');
        Route::apiResource('payment-methods', PaymentMethodController::class)->middleware('permission:payment_methods.manage');
        Route::post('payment-methods/{paymentMethod}/restore', [PaymentMethodController::class, 'restore'])->middleware('permission:payment_methods.manage');

        Route::get('payment-gateways/providers', [PaymentGatewayController::class, 'providers'])->middleware('permission:payment_gateways.view');
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->middleware('permission:payment_gateways.view');
        Route::post('payment-gateways', [PaymentGatewayController::class, 'store'])->middleware('permission:payment_gateways.create');
        Route::get('payment-gateways/{gateway}', [PaymentGatewayController::class, 'show'])->middleware('permission:payment_gateways.view');
        Route::put('payment-gateways/{gateway}', [PaymentGatewayController::class, 'update'])->middleware('permission:payment_gateways.update');
        Route::patch('payment-gateways/{gateway}/status', [PaymentGatewayController::class, 'status'])->middleware('permission:payment_gateways.update');
        Route::post('payment-gateways/{gateway}/test', [PaymentGatewayController::class, 'test'])->middleware('permission:payment_gateways.test');
        Route::post('payment-gateways/{gateway}/set-default', [PaymentGatewayController::class, 'setDefault'])->middleware('permission:payment_gateways.update');
        Route::delete('payment-gateways/{gateway}', [PaymentGatewayController::class, 'destroy'])->middleware('permission:payment_gateways.delete');
        Route::post('payment-gateways/{gateway}/restore', [PaymentGatewayController::class, 'restore'])->middleware('permission:payment_gateways.delete');

        Route::get('devices', [DeviceController::class, 'index'])->middleware('permission:devices.view');
        Route::get('devices/{device}/summary', [DeviceController::class, 'summary'])->middleware('permission:devices.view');
        Route::get('devices/{device}/events', [DeviceEventController::class, 'index'])->middleware('permission:devices.events.view');
        Route::get('devices/{device}', [DeviceController::class, 'show'])->middleware('permission:devices.view');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('permission:devices.create');
        Route::put('devices/{device}', [DeviceController::class, 'update'])->middleware('permission:devices.update');
        Route::patch('devices/{device}/status', [DeviceController::class, 'updateStatus'])->middleware('permission:devices.update');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:devices.update');
        Route::post('devices/{device}/release', [DeviceController::class, 'release'])->middleware('permission:devices.release');
        Route::post('devices/{device}/enrollment-token', [DeviceEnrollmentController::class, 'createToken'])->middleware('permission:devices.enroll');
        Route::get('devices/{device}/enrollments', [DeviceEnrollmentController::class, 'index'])->middleware('permission:devices.enroll');
        Route::get('device-enrollments/{enrollment}', [DeviceEnrollmentController::class, 'show'])->middleware('permission:devices.enroll');
        Route::delete('device-enrollments/{enrollment}', [DeviceEnrollmentController::class, 'revoke'])->middleware('permission:devices.enroll');
        Route::patch('device-events/{event}/acknowledge', [DeviceEventController::class, 'acknowledge'])->middleware('permission:devices.events.manage');
        Route::get('device-events', [DeviceEventController::class, 'all'])->middleware('permission:devices.events.view');
        Route::get('device-restrictions', [DeviceController::class, 'restrictions'])->middleware('permission:devices.view');

        Route::get('devices/{device}/commands', [DeviceCommandController::class, 'index'])->middleware('permission:devices.commands.view');
        Route::post('devices/{device}/commands/warning', [DeviceCommandController::class, 'warning'])->middleware('permission:devices.warning');
        Route::post('devices/{device}/commands/partial-lock', [DeviceCommandController::class, 'partialLock'])->middleware('permission:devices.partial_lock');
        Route::post('devices/{device}/commands/full-lock', [DeviceCommandController::class, 'fullLock'])->middleware('permission:devices.full_lock');
        Route::post('devices/{device}/commands/unlock', [DeviceCommandController::class, 'unlock'])->middleware('permission:devices.unlock');
        Route::post('devices/{device}/commands/policy-sync', [DeviceCommandController::class, 'policySync'])->middleware('permission:devices.update');
        Route::post('devices/{device}/commands/refresh-status', [DeviceCommandController::class, 'refreshStatus'])->middleware('permission:devices.update');
        Route::post('devices/{device}/trigger-escalation-cycle', [DeviceController::class, 'triggerEscalationCycle'])->middleware('permission:devices.update');
        Route::get('device-commands/{command}', [DeviceCommandController::class, 'show'])->middleware('permission:devices.commands.view');
        Route::post('device-commands/{command}/cancel', [DeviceCommandController::class, 'cancel'])->middleware('permission:devices.commands.cancel');

        Route::get('lock-policies', [LockPolicyController::class, 'index'])->middleware('permission:lock_policies.view');
        Route::get('lock-policies/{policy}', [LockPolicyController::class, 'show'])->middleware('permission:lock_policies.view');
        Route::post('lock-policies', [LockPolicyController::class, 'store'])->middleware('permission:lock_policies.manage');
        Route::put('lock-policies/{policy}', [LockPolicyController::class, 'update'])->middleware('permission:lock_policies.manage');
        Route::patch('lock-policies/{policy}/status', [LockPolicyController::class, 'status'])->middleware('permission:lock_policies.manage');
        Route::post('lock-policies/{policy}/duplicate', [LockPolicyController::class, 'duplicate'])->middleware('permission:lock_policies.manage');
        Route::delete('lock-policies/{policy}', [LockPolicyController::class, 'destroy'])->middleware('permission:lock_policies.manage');
        Route::post('lock-policies/{policy}/restore', [LockPolicyController::class, 'restore'])->middleware('permission:lock_policies.manage');
        Route::put('devices/{device}/lock-policy', [LockPolicyController::class, 'assign'])->middleware('permission:lock_policies.manage');
        Route::get('devices/{device}/location', [DeviceLocationController::class, 'latest'])->middleware('permission:devices.location.view');
        Route::get('devices/{device}/locations', [DeviceLocationController::class, 'index'])->middleware('permission:devices.location.view');
        Route::put('devices/{device}/location-settings', [DeviceLocationController::class, 'settingsUpdate'])->middleware('permission:devices.location.manage');
        Route::post('devices/{device}/location-policy', [DeviceLocationController::class, 'settingsUpdate'])->middleware('permission:devices.location.manage');

        Route::get('consents', [DeviceConsentController::class, 'index'])->middleware('permission:consents.view');
        Route::get('device-consents', [DeviceConsentController::class, 'index'])->middleware('permission:consents.view');
        Route::get('consents/{consent}', [DeviceConsentController::class, 'show'])->middleware('permission:consents.view');
        Route::get('notifications', [DeviceNotificationController::class, 'index'])->middleware('permission:notifications.view');
        Route::get('device-notifications', [DeviceNotificationController::class, 'index'])->middleware('permission:notifications.view');
        Route::get('notifications/{notification}', [DeviceNotificationController::class, 'show'])->middleware('permission:notifications.view');
        Route::get('retention-policies', [DataRetentionPolicyController::class, 'index'])->middleware('permission:retention.view');
        Route::get('retention-policies/{policy}', [DataRetentionPolicyController::class, 'show'])->middleware('permission:retention.view');
        Route::put('retention-policies/{policy}', [DataRetentionPolicyController::class, 'update'])->middleware('permission:retention.manage');
        Route::post('retention-policies/{policy}/apply', [DataRetentionPolicyController::class, 'apply'])->middleware('permission:retention.manage');
        Route::get('releases', [DeviceReleaseController::class, 'index'])->middleware('permission:releases.view');
        Route::get('device-releases', [DeviceReleaseController::class, 'index'])->middleware('permission:releases.view');
        Route::get('releases/{release}', [DeviceReleaseController::class, 'show'])->middleware('permission:releases.view');
        Route::post('emi-accounts/{emiAccount}/complete', [EmiAccountController::class, 'complete'])->middleware('permission:emi.complete');

        Route::get('subscription/current', [SubscriptionController::class, 'current']);
        Route::get('subscription/plans', [SubscriptionController::class, 'plans']);
        Route::post('subscription/quote', [SubscriptionController::class, 'quote']);
        Route::post('subscription/apply-promo', [SubscriptionController::class, 'applyPromo']);
        Route::post('subscription/recharge', [SubscriptionController::class, 'recharge']);
        Route::post('subscription/order', [SubscriptionController::class, 'createOrder']);
        Route::post('subscription/verify', [SubscriptionController::class, 'verifyPayment']);
        Route::get('subscription/history', [SubscriptionController::class, 'history']);
        Route::get('subscription/history/{recharge}', [SubscriptionController::class, 'receipt']);
        Route::post('subscription/history/{recharge}/notify', [SubscriptionController::class, 'sendNotification']);
        Route::post('subscription/recharges/{recharge}/activate', [SubscriptionController::class, 'activate']);
        Route::post('subscription/recharges/{recharge}/pause', [SubscriptionController::class, 'pause']);
        Route::post('subscription/recharges/{recharge}/resume', [SubscriptionController::class, 'resume']);
        Route::post('subscription/recharges/{recharge}/transfer', [SubscriptionController::class, 'transfer']);
        Route::post('subscription/recharges/{recharge}/refund', [SubscriptionController::class, 'refund']);
        Route::get('subscription/webhook-logs', [SubscriptionController::class, 'webhookLogs']);

        // Section: CRM, Field Shop Visits, Client Projects & Bulk Messaging
        Route::apiResource('crm/leads', CrmLeadController::class)->middleware('permission:crm.leads.manage');
        Route::post('crm/leads/{lead}/convert', [CrmLeadController::class, 'convert'])->middleware('permission:crm.leads.manage');

        Route::apiResource('crm/visits', CrmVisitController::class)->middleware('permission:crm.visits.manage');

        Route::apiResource('crm/projects', CrmProjectController::class)->middleware('permission:crm.projects.manage');
        Route::post('crm/projects/{project}/payments', [CrmProjectController::class, 'addPayment'])->middleware('permission:crm.projects.manage');

        Route::get('crm/messages', [CrmMessageController::class, 'index'])->middleware('permission:crm.messages.view');
        Route::get('crm/messages/audiences', [CrmMessageController::class, 'audiences'])->middleware('permission:crm.messages.view');
        Route::post('crm/messages/send', [CrmMessageController::class, 'send'])->middleware('permission:crm.messages.manage');
        Route::post('crm/messages/test-gateway', [CrmMessageController::class, 'testGateway'])->middleware('permission:crm.messages.manage');
        Route::post('crm/messages/bulk-delete', [CrmMessageController::class, 'bulkDelete'])->middleware('permission:crm.messages.manage');
        Route::delete('crm/messages/{crmMessage}', [CrmMessageController::class, 'destroy'])->middleware('permission:crm.messages.manage');
    });
});
