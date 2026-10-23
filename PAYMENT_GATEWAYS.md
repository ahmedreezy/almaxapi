# Payment gateway operations

Almax supports JPesa and Relworx. The active gateway is selected from the
developer console and applies only to new payment attempts. Existing attempts,
callbacks, status checks, and commission payouts remain pinned to the provider
recorded on the payment.

## Relworx credentials

Create a production organization and business account in Relworx, enable UGX
collections and payouts, then create an API key authorized for that business
account. Copy the API key when it is created because Relworx does not display it
again. In the business-account settings, copy the account number and webhook
authentication key, authorize the production server's public outbound IP, and
configure the request-payment and send-payment webhooks to the exact URL below.

Add these values only to the production API's untracked `.env`:

```dotenv
PAYMENT_GATEWAY_DEFAULT=jpesa
PAYMENT_CURRENCY=UGX
PAYMENT_AGENT_COMMISSION_ENABLED=true
PAYMENT_AGENT_COMMISSION_RATIO=0.2
PAYMENT_COMMISSION_RECIPIENT_MSISDN=+2567XXXXXXXX

RELWORX_API_URL=https://payments.relworx.com/api
RELWORX_API_KEY=copy-once-api-key
RELWORX_ACCOUNT_NO=RELXXXXXXXXXX
RELWORX_WEBHOOK_KEY=business-webhook-key
RELWORX_WEBHOOK_URL=https://www.almaxpredictions.com/api/payments/webhooks/relworx
RELWORX_WEBHOOK_TOLERANCE_SECONDS=300
```

Do not add payment credentials to the frontend, Git, or GitHub Actions. The
current deployment workflow preserves the server `.env`, so no new GitHub
Secrets are needed.

## Production rollout

1. Add the Relworx environment values while JPesa remains active.
2. Deploy the API from `main`; the deployment runs the gateway migration and
   rebuilds Laravel's configuration cache.
3. Configure the exact webhook URL and server IP in Relworx.
4. Deploy the frontend from its `main` branch.
5. Open Developer Console → Payment Gateway and run **Test connection**.
6. Switch to Relworx only after the check succeeds.
7. Make a low-value Airtel collection, confirm subscription activation, and
   confirm the commission payout reaches the configured mobile number.
8. If necessary, switch new attempts back to JPesa. Relworx attempts already in
   progress will continue to be handled by Relworx.

After changing `.env` manually, run:

```bash
php artisan optimize:clear
php artisan config:cache
```
