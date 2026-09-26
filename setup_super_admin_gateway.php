<?php
$gateway = new App\Models\PaymentGateway();
$gateway->company_id = null;
$gateway->provider = 'razorpay';
$gateway->display_name = 'Platform Razorpay';
$gateway->environment = 'test';
$gateway->is_enabled = true;
$gateway->is_default = true;
$gateway->public_key = 'rzp_test_TaIDQJe8TFX2xl';
$gateway->secret = 'Yl7d7L86v3f2x0w9tLp0zQ9R'; // Just some dummy secret for test
$gateway->status = 'configured';
$gateway->save();
echo "Payment gateway for super admin created!\n";
