<?php

namespace App\Contracts;

use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /** @return array{collections: bool, payouts: bool} */
    public function capabilities(): array;

    /** @return array{configured: bool, ready: bool, missing: array<int, string>, message: string} */
    public function readiness(bool $live = false): array;

    public function initiateCollection(string $phone, float $amount, string $reference, string $paymentMethod): array;

    public function queryCollection(Payment $payment, ?string $manualReference = null): array;

    public function initiatePayout(int $amount, string $reference, string $description, string $mobile): array;

    public function verifyWebhook(Request $request): bool;

    public function parseWebhook(Request $request): array;
}
