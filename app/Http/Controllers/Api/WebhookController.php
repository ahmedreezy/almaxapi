<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * POST /webhook/github — GitHub push webhook for auto-deployment.
 *
 * Security: verifies HMAC-SHA256 signature using WEBHOOK_SECRET env var.
 * Only triggers on pushes to the main branch.
 */
class WebhookController extends Controller
{
    public function github(Request $request): JsonResponse
    {
        $secret = config('app.webhook_secret');

        if (empty($secret)) {
            // Webhook secret not configured — refuse all requests for security
            return response()->json(['error' => 'Webhook not configured.'], 503);
        }

        // Verify HMAC-SHA256 signature
        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature) {
            return response()->json(['error' => 'Missing signature.'], 401);
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();

        // Only deploy on pushes to main branch
        $ref = $payload['ref'] ?? '';
        if ($ref !== 'refs/heads/main') {
            return response()->json(['message' => 'Not main branch — ignored.']);
        }

        // Run deploy script asynchronously (non-blocking)
        $deployScript = base_path('../apps/newbet/deploy.sh');
        if (file_exists($deployScript)) {
            Process::start("bash {$deployScript}");
        }

        return response()->json(['message' => 'Deployment triggered.']);
    }
}
