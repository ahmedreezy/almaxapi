<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentGatewaySwitch;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentGatewayController extends Controller
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function show(): JsonResponse
    {
        $setting = PaymentGatewaySetting::current()->load('updatedBy');
        $lastSwitch = PaymentGatewaySwitch::query()->latest('id')->first();

        return response()->json([
            'activeProvider' => $setting->active_provider,
            'providers' => collect(PaymentGatewayManager::PROVIDERS)->mapWithKeys(function (string $provider) {
                $gateway = $this->gateways->gateway($provider);

                return [$provider => [
                    'name' => $provider === 'jpesa' ? 'JPesa' : 'Relworx',
                    ...$gateway->readiness(false),
                    'capabilities' => $gateway->capabilities(),
                ]];
            }),
            'lastChangedAt' => $setting->updated_at,
            'lastChangedBy' => $setting->updatedBy?->username,
            'lastSwitch' => $lastSwitch ? [
                'from' => $lastSwitch->from_provider,
                'to' => $lastSwitch->to_provider,
                'at' => $lastSwitch->created_at,
            ] : null,
        ]);
    }

    public function check(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, PaymentGatewayManager::PROVIDERS, true), 404);
        $result = $this->gateways->gateway($provider)->readiness(true);

        return response()->json($result, $result['ready'] ? 200 : 422);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(PaymentGatewayManager::PROVIDERS)],
        ]);
        $provider = strtolower($data['provider']);
        $readiness = $this->gateways->gateway($provider)->readiness(true);
        if (! $readiness['ready']) {
            return response()->json([
                'message' => $readiness['message'],
                'readiness' => $readiness,
            ], 422);
        }

        $actorId = $request->user()?->id;
        DB::transaction(function () use ($provider, $actorId) {
            $setting = PaymentGatewaySetting::query()->lockForUpdate()->find(1) ?? PaymentGatewaySetting::current();
            $from = $setting->active_provider;
            if ($from === $provider) {
                return;
            }

            $setting->update(['active_provider' => $provider, 'updated_by' => $actorId]);
            PaymentGatewaySwitch::create([
                'from_provider' => $from,
                'to_provider' => $provider,
                'changed_by' => $actorId,
                'created_at' => now(),
            ]);
        });

        return $this->show();
    }
}
