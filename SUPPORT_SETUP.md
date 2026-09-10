# Almax WhatsApp AI Support Setup

The Laravel API now receives Twilio WhatsApp messages, processes them through
OpenAI, allows customer-scoped account lookups, enforces daily reply limits,
and exposes admin APIs for human takeover and knowledge management.

Accounts and services required:

- OpenAI API organization/project with billing
- Twilio account with billing and a WhatsApp sender
- Meta Business Portfolio and WhatsApp Business Account, connected through Twilio
- Existing Almax hosting, HTTPS domain, PostgreSQL database, and queue worker
- Existing J-Pesa merchant/API access for live pending-payment verification

## 1. Deploy the backend

The public URLs must use HTTPS and `APP_URL` must be the same public origin
configured in Twilio. Twilio request signatures include the exact webhook URL.

```ini
APP_URL=https://almaxpredictions.com

TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_WHATSAPP_FROM=
TWILIO_INBOUND_WEBHOOK_URL=https://almaxpredictions.com/api/support/twilio/inbound
TWILIO_STATUS_WEBHOOK_URL=https://almaxpredictions.com/api/support/twilio/status

OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4-mini
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_TIMEOUT_SECONDS=45

SUPPORT_DAILY_REPLY_LIMIT=10
SUPPORT_TIMEZONE=Africa/Kampala
SUPPORT_MAX_OUTPUT_TOKENS=800
SUPPORT_HISTORY_MESSAGES=10
SUPPORT_KNOWLEDGE_ARTICLES=8
SUPPORT_MESSAGE_RETENTION_DAYS=365
```

Run:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Run a continuously supervised worker when the host supports it:

```bash
php artisan queue:work database --queue=support,default --sleep=1 --tries=3 --timeout=90 --max-time=3600
```

If cPanel cannot supervise a permanent process, add this cron every minute:

```cron
* * * * * cd /absolute/path/to/almaxapi && php artisan queue:work database --queue=support,default --stop-when-empty --tries=3 --timeout=90 >> /dev/null 2>&1
```

Keep the existing scheduler cron as well:

```cron
* * * * * cd /absolute/path/to/almaxapi && php artisan schedule:run >> /dev/null 2>&1
```

## 2. Create the OpenAI project and key

1. Sign in at <https://platform.openai.com/> and add a payment method under Billing.
2. Open the project selector and create a project named **Almax WhatsApp Support**.
3. Open that project's **API keys** page and choose **Create new secret key**.
4. Copy the key once and place it in the server `.env` as `OPENAI_API_KEY`.
5. In the project's **Limits** area, set a monthly budget and notification threshold.
6. Set `OPENAI_MODEL=gpt-5.4-mini`; the model can later be changed without code edits.
7. Never place the key in Vue, JavaScript, source control, screenshots, or chat messages.

Official references:

- <https://platform.openai.com/docs/api-reference/project-api-keys>
- <https://help.openai.com/en/articles/9186755-managing-your-work-in-the-api-platform-with-projects>
- <https://developers.openai.com/api/reference/cli/resources/responses/methods/create>

This implementation uses the Responses API with `store: false`, strict structured
output, and server-side function tools. It does not require an OpenAI vector
store; published knowledge is selected locally and included in the request.

## 3. Open Twilio and activate the test Sandbox

1. Create an account at <https://www.twilio.com/try-twilio>.
2. Verify the account email and phone, then upgrade the account and add credit.
3. From the Twilio Console dashboard, copy **Account SID** and reveal/copy the
   primary **Auth Token**. Put them in `TWILIO_ACCOUNT_SID` and
   `TWILIO_AUTH_TOKEN` on the server.
4. In Twilio Console, open **Messaging → Try it out → Send a WhatsApp message**.
5. Activate the test environment. On your phone, scan the QR code or send the
   displayed `join <sandbox-code>` message to the Sandbox number.
6. Open **Sandbox settings** and set **When a message comes in** to:
   `https://almaxpredictions.com/api/support/twilio/inbound`, method **POST**.
7. Set **Status callback URL** to:
   `https://almaxpredictions.com/api/support/twilio/status`, method **POST**.
8. Set `TWILIO_WHATSAPP_FROM` to the Sandbox sender in E.164 form, normally
   `+14155238886`, without the `whatsapp:` prefix.
9. Refresh Laravel configuration with `php artisan config:cache` and ensure the
   support queue worker is running.
10. Send a text message from the joined phone. The first response must start:
   **Hello, this is Almax Predictions. How can we help you today?**

Official references:

- <https://www.twilio.com/docs/whatsapp/quickstart>
- <https://www.twilio.com/docs/whatsapp/sandbox>
- <https://www.twilio.com/docs/usage/security>

## 4. Register the production WhatsApp sender

1. In Twilio Console, open **Messaging → Senders → WhatsApp Senders**.
2. Select **Create new sender**, then **Continue with Facebook**.
3. Sign in using a Facebook account with full administrator access to the Almax
   Meta Business Portfolio, or create the portfolio in the embedded flow.
4. Create/select the WhatsApp Business Account requested by Twilio.
5. Create the business profile with the Almax Predictions display name, website,
   business category, description, and support contact information.
6. Choose a Twilio number or enter a business-owned number that can receive an
   SMS or voice OTP. A non-Twilio number must not already be registered to a
   normal WhatsApp account unless it is migrated using Twilio's supported flow.
7. Receive and enter the OTP, review Twilio's requested WABA access, and finish
   sender registration.
8. Open the registered sender's configuration and set the same inbound and
   status callback URLs used for the Sandbox, both with method **POST**.
9. Replace `TWILIO_WHATSAPP_FROM` with the registered production number and run
   `php artisan config:cache`.
10. Send an inbound message to open WhatsApp's customer-service window and run
    a final end-to-end test. Free-form replies outside that window require an
    approved WhatsApp template.

Official references:

- <https://www.twilio.com/docs/whatsapp/self-sign-up>
- <https://www.twilio.com/docs/whatsapp/api>

## 5. Confirm J-Pesa merchant/API access

The bot always checks the Almax database first. When a stored payment remains
pending but has a provider transaction ID, it can also use the existing J-Pesa
query integration. No second payment-provider account is needed if Almax's
current merchant account and API key are already active.

1. If needed, create a merchant account at <https://www.jpesa.com/merchants.php>.
2. Complete the account profile and submit the KYC documents for the applicable
   business type. J-Pesa lists the required documents at
   <https://my.jpesa.com/account-opening-documents/>.
3. Ask J-Pesa merchant support to enable API collections and transaction-query
   access if those facilities are not already enabled on the account.
4. Obtain the merchant API key from the account/API area or from J-Pesa support.
   Put it in the server `.env` as `JPESA_API_KEY`; never expose it to the web app.
5. Set `JPESA_API_URL=https://my.jpesa.com/api/` and
   `JPESA_CALLBACK_URL=https://almaxpredictions.com/api/payments/webhook`.
6. Send a sandbox/test transaction, confirm the callback updates the matching
   Almax payment, and confirm a transaction query returns the same status.
7. If J-Pesa provides a callback signing secret for your account, set it as
   `MOBILE_MONEY_WEBHOOK_SECRET` and test the exact signature format with them.

J-Pesa merchant support is listed at <https://my.jpesa.com/contacts/>. Do not let
the AI mark a payment successful merely because a customer submits an SMS or
transaction ID; the implemented tool reports only database/provider results.

## 6. Publish support knowledge

Authenticate as an Almax administrator, then use the knowledge endpoints:

- `GET /api/support/admin/knowledge`
- `POST /api/support/admin/knowledge`
- `PATCH /api/support/admin/knowledge/{id}`
- `DELETE /api/support/admin/knowledge/{id}`

Example published article body:

```json
{
  "title": "Pending mobile-money payment",
  "question": "What should a customer do when payment is pending?",
  "answer": "Do not pay again while the first request is being verified. Share the payment reference if available so we can check it.",
  "keywords": ["payment", "pending", "receipt", "mobile money"],
  "locale": "en",
  "status": "published"
}
```

Create separate `lg` articles for approved Luganda wording. Draft articles are
never sent to the model.

## 7. Human takeover and monitoring

Admin endpoints:

- `GET /api/support/admin/conversations`
- `GET /api/support/admin/conversations/{id}`
- `PATCH /api/support/admin/conversations/{id}`
- `POST /api/support/admin/conversations/{id}/reply`
- `GET /api/support/admin/metrics`

Set `mode` to `human` to stop automated replies. An admin reply automatically
sets this mode. Set `mode` back to `ai` to return control, or set `status` to
`resolved` to close the case. Human messages do not consume the customer's AI
allowance. Set `dailyLimit` on the conversation update endpoint to override the
global allowance for that contact; use `null` to restore the global default or
`0` to disable AI replies for that contact.

Monitor:

```bash
php artisan queue:failed
php artisan route:list --path=support
tail -f storage/logs/laravel.log
```

Twilio delivery states (`queued`, `sent`, `delivered`, `read`, and `failed`) are
recorded through the status callback. Token totals and daily AI replies are
available from the admin metrics endpoint.
