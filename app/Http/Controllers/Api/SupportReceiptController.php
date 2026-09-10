<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Response;

class SupportReceiptController extends Controller
{
    public function show(Payment $payment): Response
    {
        abort_unless(in_array($payment->status, ['confirmed', 'success', 'successful', 'completed'], true), 404);
        $payment->loadMissing(['user', 'subscription.group']);

        return response()->view('support.receipt', [
            'payment' => $payment,
            'package' => $payment->subscription?->group?->name ?? $payment->plan_type,
        ])->header('Cache-Control', 'private, no-store');
    }
}
