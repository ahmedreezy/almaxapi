<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
use App\Services\Payments\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletLedgerService $wallets,
    ) {}

    public function developerShow(Request $request): JsonResponse
    {
        return response()->json([
            'wallet' => $this->wallets->providerSummary('developer', 'relworx'),
            'destination_configured' => trim((string) config('services.payouts.developer_msisdn', '')) !== '',
            'execution_mode' => config('services.payouts.execution_mode', 'request_only'),
            'requests' => $this->requests('developer', $request->user()?->id),
        ]);
    }

    public function developerStore(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);
        $payout = $this->wallets->createPayoutRequest('developer', (float) $data['amount'], (int) $request->user()->id);

        return response()->json([
            'message' => 'Payout request reserved. It is awaiting an authorized payout connection.',
            'request' => $this->wallets->serialiseRequest($payout->load('requester')),
            'wallet' => $this->wallets->providerSummary('developer', 'relworx'),
        ], 201);
    }

    public function developerCancel(Request $request, PayoutRequest $payoutRequest): JsonResponse
    {
        $payout = $this->wallets->cancel($payoutRequest, (int) $request->user()->id, 'developer');

        return response()->json([
            'message' => 'Payout request cancelled and reserved funds released.',
            'request' => $this->wallets->serialiseRequest($payout->load('requester')),
            'wallet' => $this->wallets->providerSummary('developer', 'relworx'),
        ]);
    }

    public function adminShow(): JsonResponse
    {
        return response()->json([
            'wallet' => $this->wallets->providerSummary('owner', 'relworx'),
            'destination_configured' => trim((string) config('services.payouts.owner_msisdn', '')) !== '',
            'execution_mode' => config('services.payouts.execution_mode', 'request_only'),
            'requests' => $this->requests(),
        ]);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['requests' => $this->requests()]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);
        $payout = $this->wallets->createPayoutRequest('owner', (float) $data['amount'], (int) $request->user()->id);

        return response()->json([
            'message' => 'Payout request reserved. It is awaiting an authorized payout connection.',
            'request' => $this->wallets->serialiseRequest($payout->load('requester')),
            'wallet' => $this->wallets->providerSummary('owner', 'relworx'),
        ], 201);
    }

    public function adminCancel(Request $request, PayoutRequest $payoutRequest): JsonResponse
    {
        $payout = $this->wallets->cancel($payoutRequest, (int) $request->user()->id, 'owner');

        return response()->json([
            'message' => 'Payout request cancelled and reserved funds released.',
            'request' => $this->wallets->serialiseRequest($payout->load('requester')),
            'wallet' => $this->wallets->providerSummary('owner', 'relworx'),
        ]);
    }

    private function requests(?string $beneficiary = null, ?int $requesterId = null): array
    {
        return PayoutRequest::query()
            ->with('requester')
            ->where('provider', 'relworx')
            ->when($beneficiary, fn ($query) => $query->where('beneficiary', $beneficiary))
            ->when($requesterId, fn ($query) => $query->where('requested_by', $requesterId))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PayoutRequest $item) => $this->wallets->serialiseRequest($item))
            ->values()
            ->all();
    }
}
