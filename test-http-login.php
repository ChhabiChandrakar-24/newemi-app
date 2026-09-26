<?php
$ch = curl_init('http://127.0.0.1:8000/api/v1/auth/login');
$data = json_encode([
    'email' => 'demo.superadmin@example.test',
    'password' => 'Demo@12345678',
    'device_name' => 'admin-web'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Origin: http://localhost:5173'
]);
$response = curl_exec($ch);
$json = json_decode($response, true);
$token = $json['token'] ?? null;
curl_close($ch);

echo "Token: $token\n";

if ($token) {
    $ch2 = curl_init('http://127.0.0.1:8000/api/v1/me');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'Origin: http://localhost:5173'
    ]);
    $res2 = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    echo "ME HTTP Code: $code2\n";
    echo "ME Response: $res2\n";
}
