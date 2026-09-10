<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Almax Predictions Receipt</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f4f4;color:#171717;margin:0;padding:32px}.receipt{max-width:620px;margin:auto;background:white;border-top:6px solid #d7aa00;padding:32px;box-shadow:0 8px 30px #0002}.brand{font-size:24px;font-weight:800}.muted{color:#666}.row{display:flex;justify-content:space-between;gap:24px;border-bottom:1px solid #eee;padding:12px 0}.paid{color:#087a42;font-weight:700}.footer{margin-top:28px;font-size:12px;color:#777}@media print{body{background:white;padding:0}.receipt{box-shadow:none}}
    </style>
</head>
<body>
<main class="receipt">
    <div class="brand">ALMAX PREDICTIONS</div>
    <p class="muted">Payment receipt</p>
    <div class="row"><span>Receipt</span><strong>{{ $payment->receipt_number }}</strong></div>
    <div class="row"><span>Customer</span><strong>{{ $payment->user?->username ?? 'Almax customer' }}</strong></div>
    <div class="row"><span>Package</span><strong>{{ $package }}</strong></div>
    <div class="row"><span>Amount</span><strong>UGX {{ number_format((float) $payment->amount) }}</strong></div>
    <div class="row"><span>Method</span><strong>{{ strtoupper((string) $payment->payment_method) }}</strong></div>
    <div class="row"><span>Reference</span><strong>{{ $payment->payment_reference }}</strong></div>
    <div class="row"><span>Date</span><strong>{{ $payment->created_at?->timezone('Africa/Kampala')->format('d M Y, H:i') }}</strong></div>
    <div class="row"><span>Status</span><strong class="paid">PAID</strong></div>
    <p class="footer">This secure receipt link expires automatically. Keep the receipt number for your records.</p>
</main>
</body>
</html>
