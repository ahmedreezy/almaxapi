<?php

declare(strict_types=1);

// A deliberately narrow development proxy for temporary HTTPS tunnels.
// Only Twilio's two POST callbacks can reach the local Laravel server.

$allowedPaths = [
    '/api/support/twilio/inbound',
    '/api/support/twilio/status',
];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST' || ! in_array($path, $allowedPaths, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found\n";
    exit;
}

$body = file_get_contents('php://input');
if ($body === false || strlen($body) > 1_048_576) {
    http_response_code(413);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Payload too large\n";
    exit;
}

$headers = [];
foreach (['CONTENT_TYPE' => 'Content-Type', 'HTTP_X_TWILIO_SIGNATURE' => 'X-Twilio-Signature'] as $serverKey => $headerName) {
    if (isset($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
        $headers[] = $headerName.': '.$_SERVER[$serverKey];
    }
}

$request = curl_init('http://127.0.0.1:8000'.$path);
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 95,
]);

$response = curl_exec($request);
$status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);

if ($response === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Local application unavailable\n";
    curl_close($request);
    exit;
}

curl_close($request);
http_response_code($status > 0 ? $status : 502);
header('Content-Type: '.($path === '/api/support/twilio/inbound'
    ? 'text/xml; charset=UTF-8'
    : 'text/plain; charset=UTF-8'));
echo $response;
