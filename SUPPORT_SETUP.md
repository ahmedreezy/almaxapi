# Almax WhatsApp AI Support Setup

The Laravel API now receives Twilio WhatsApp messages, processes them through
OpenAI, allows customer-scoped account lookups, enforces daily reply limits,
and exposes admin APIs for knowledge and conversation management.

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
OPENAI_TIMEOUT_SECONDS=20
OPENAI_CONNECT_TIMEOUT_SECONDS=5
OPENAI_MAX_ATTEMPTS=2
OPENAI_REASONING_EFFORT=none
OPENAI_VERBOSITY=low

SUPPORT_DAILY_REPLY_LIMIT=10
SUPPORT_TIMEZONE=Africa/Kampala
SUPPORT_MAX_OUTPUT_TOKENS=350
SUPPORT_HISTORY_MESSAGES=10
SUPPORT_KNOWLEDGE_ARTICLES=8
SUPPORT_KNOWLEDGE_FAST_PATH_CONFIDENCE=0.76
SUPPORT_PLATFORM_SYNC=true
SUPPORT_PLATFORM_SYNC_BUDGET_SECONDS=12
SUPPORT_MESSAGE_RETENTION_DAYS=365
SUPPORT_JOB_TIMEOUT_SECONDS=60
SUPPORT_JOB_TRIES=1
SUPPORT_OPENAI_BUDGET_SECONDS=50
SUPPORT_OPENAI_MAX_ROUNDS=3
DB_QUEUE_RETRY_AFTER=90
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
php artisan queue:work database --queue=support --sleep=1 --tries=1 --timeout=60 --max-time=3600
```

For Supervisor, copy `scripts/almax-support-worker.conf.example`, replace the
PHP and application paths, and load it through Supervisor or the hosting
process manager.

If cPanel cannot supervise a permanent process, add this cron every minute:

```cron
* * * * * cd /absolute/path/to/almaxapi && php artisan queue:work database --queue=support --stop-when-empty --tries=1 --timeout=60 >> /dev/null 2>&1
```

The cron form is a fallback only and can add almost 60 seconds before a reply
starts. WhatsApp and platform chat with `SUPPORT_PLATFORM_SYNC=false` should use
Supervisor, systemd, or the cPanel Process Manager to keep the worker running
continuously. With `SUPPORT_PLATFORM_SYNC=true`, website chat is processed in
the request with a strict 12-second budget; published high-confidence knowledge
answers and live package discovery normally complete without an OpenAI request.

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
2. Verify the account email and phone. A paid upgrade is not required for the
   WhatsApp trial Sandbox; upgrade only when you are ready for a production
   sender or after the trial allowance/period ends.
3. From the Twilio Console dashboard, copy **Account SID** and reveal/copy the
   primary **Auth Token**. Use the normal credentials shown on the account
   dashboard, even while the account is in trial mode. Do **not** use the
   separate **Test Account SID** and **Test Auth Token**: those simulate a small
   set of REST API operations, do not deliver messages, and do not trigger
   callbacks, so they cannot exercise this WhatsApp webhook flow end to end.
4. Add the values only to the deployed Laravel API's untracked `.env` file:

   ```ini
   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_AUTH_TOKEN=your_primary_auth_token
   TWILIO_WHATSAPP_FROM=+14155238886
   TWILIO_INBOUND_WEBHOOK_URL=https://almaxpredictions.com/api/support/twilio/inbound
   TWILIO_STATUS_WEBHOOK_URL=https://almaxpredictions.com/api/support/twilio/status
   ```

   The Account SID maps to `TWILIO_ACCOUNT_SID`; the primary Auth Token maps to
   `TWILIO_AUTH_TOKEN`; and the Sandbox sender shown by Twilio maps to
   `TWILIO_WHATSAPP_FROM`. Never add these values to `.env.example`, Vue code,
   source control, screenshots, or chat messages.
5. In Twilio Console, open **Messaging → Try it out → Send a WhatsApp message**.
6. Activate the test environment. On your phone, scan the QR code or send the
   displayed `join <sandbox-code>` message to the Sandbox number.
7. Open **Sandbox settings** and set **When a message comes in** to:
   `https://almaxpredictions.com/api/support/twilio/inbound`, method **POST**.
8. Set **Status callback URL** to:
   `https://almaxpredictions.com/api/support/twilio/status`, method **POST**.
9. Confirm `TWILIO_WHATSAPP_FROM` matches the Sandbox sender in E.164 form,
   normally
   `+14155238886`, without the `whatsapp:` prefix.
10. Refresh Laravel configuration with `php artisan optimize:clear`, then
    `php artisan config:cache`, and ensure the
   support queue worker is running.
11. Send a text message from the joined phone. The first response must start:
   **Hello, this is Almax Predictions. How can we help you today?**

Official references:

- <https://www.twilio.com/docs/whatsapp/quickstart>
- <https://www.twilio.com/docs/whatsapp/sandbox>
- <https://www.twilio.com/docs/usage/security>

## Test the complete flow on localhost

There are two complementary tests. Run the automated test first; it does not
contact Twilio or OpenAI and does not require real credentials. Then use the
Sandbox test to prove the public webhook, queue worker, OpenAI response, and
outbound WhatsApp delivery together.

### A. Run the automated integration test

Install dependencies and run the focused suite:

```bash
composer install
./vendor/bin/phpunit --configuration phpunit.support.xml --do-not-cache-result
```

The test environment uses an in-memory SQLite database. The suite verifies
Twilio request signatures, rejection of invalid signatures, duplicate-message
idempotency, queue dispatch, daily quotas, AI-only recovery, mocked outbound
Twilio requests, mocked OpenAI tool calls, and the required Almax greeting.

### B. Prepare the local Laravel application

Real secrets belong in `.env`, never `.env.example`. If `.env` does not exist,
create it from the template, create the SQLite file, and initialize Laravel:

```bash
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
```

Set these initial local values in `.env`:

```ini
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/almaxapi/database/database.sqlite
CACHE_STORE=database
QUEUE_CONNECTION=database

TWILIO_ACCOUNT_SID=your_normal_twilio_account_sid
TWILIO_AUTH_TOKEN=your_rotated_primary_auth_token
TWILIO_WHATSAPP_FROM=the_sender_shown_in_your_twilio_sandbox

OPENAI_API_KEY=your_openai_project_api_key
OPENAI_MODEL=gpt-5.4-mini
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_TIMEOUT_SECONDS=20
OPENAI_CONNECT_TIMEOUT_SECONDS=5
OPENAI_MAX_ATTEMPTS=2
OPENAI_REASONING_EFFORT=none
OPENAI_VERBOSITY=low
```

The OpenAI account must have billing or credits enabled because
`gpt-5.4-mini` is not available on the API free tier.

### C. Expose localhost through HTTPS

Twilio cannot call `http://localhost`, so use a public HTTPS tunnel. For
example, install ngrok, start Laravel in one terminal, and start the tunnel in
another:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

```bash
ngrok http 8000
```

Copy the HTTPS forwarding origin printed by ngrok, for example
`https://example.ngrok-free.app`. Update the same `.env` file using that exact
origin:

```ini
APP_URL=https://example.ngrok-free.app
TWILIO_INBOUND_WEBHOOK_URL=https://example.ngrok-free.app/api/support/twilio/inbound
TWILIO_STATUS_WEBHOOK_URL=https://example.ngrok-free.app/api/support/twilio/status
```

Then reload Laravel configuration:

```bash
php artisan config:clear
php artisan support:doctor --channel=platform --probe-openai
```

The configured URLs must exactly match the URLs Twilio calls. The application
uses those exact strings when validating `X-Twilio-Signature`; a stale or
different tunnel hostname will cause a `403 Invalid Twilio signature` response.

### D. Point the Twilio Sandbox at localhost

In **Messaging → Try it out → Send a WhatsApp message → Sandbox settings**:

1. Set **When a message comes in** to
   `https://example.ngrok-free.app/api/support/twilio/inbound` using **POST**.
2. Set **Status callback URL** to
   `https://example.ngrok-free.app/api/support/twilio/status` using **POST**.
3. Save the settings.
4. From each test phone, scan the Sandbox QR code or send the displayed
   `join <sandbox-code>` message. Only joined phones can receive Sandbox
   messages.

Every time ngrok generates a new hostname, update all three `.env` URL values,
both Twilio Sandbox URLs, and run `php artisan config:clear` again.

### E. Start the support worker and test

Keep Laravel and ngrok running. In a third terminal, start the queue worker:

```bash
php artisan queue:work database --queue=support --sleep=1 --tries=1 --timeout=60
```

Send `Hello` from the joined WhatsApp phone to the Sandbox sender. A successful
test has all of these results:

1. The ngrok inspector shows a signed Twilio `POST` to the inbound URL with an
   HTTP `200` response.
2. The queue terminal processes `ProcessSupportMessage` successfully.
3. WhatsApp receives a reply beginning with
   `Hello, this is Almax Predictions. How can we help you today?`.
4. Twilio Messaging Logs show the outbound message progressing through delivery
   states such as `queued`, `sent`, `delivered`, or `read`.
5. Laravel stores one inbound and one outbound support message.

Inspect the local application while testing:

```bash
tail -f storage/logs/laravel.log
php artisan queue:failed
php artisan tinker --execute="dump(App\\Models\\SupportMessage::latest()->take(5)->get(['direction', 'sender_type', 'delivery_status', 'body'])->toArray());"
```

If the webhook returns `403`, verify the tunnel URLs and Auth Token, then clear
the config cache. If the inbound request is `200` but no reply arrives, inspect
the queue terminal and Laravel log first; the most common causes are a stopped
worker, missing OpenAI billing/key, an expired Sandbox join, or a phone that did
not join this Sandbox.

The `support:doctor` command never prints secret values. It contacts OpenAI only
when `--probe-openai` is supplied and never sends a Twilio message. All checks
must show `PASS` before the live end-to-end test.

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

The AI receives knowledge through two layers:

1. `resources/support/almax.md` is the version-controlled core service brief.
   Put stable facts and safety boundaries here: what Almax is, the customer
   journey, supported plan types, payment flow, access rules, and information
   the assistant must never invent or disclose.
2. Published `support_knowledge_articles` are editable operational FAQs. Put
   changeable customer-facing information here, such as current support
   procedures, approved policy wording, campaign details, common problems, and
   Luganda translations. Relevant articles are selected for each message.

Install the baseline English articles idempotently with:

```bash
php artisan db:seed --class=SupportKnowledgeSeeder
```

The assistant also has a `get_available_packages` tool. It reads current public
package names, prices, odds types, durations, and deadlines from the database,
while intentionally withholding paid betslip links and codes. Do not copy
current prices into the core brief or FAQs when they can be read dynamically.

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

## 7. AI-only conversation monitoring

Admin endpoints:

- `GET /api/support/admin/conversations`
- `GET /api/support/admin/conversations/{id}`
- `PATCH /api/support/admin/conversations/{id}`
- `GET /api/support/admin/metrics`

All active conversations run in `ai` mode. Set `status` to `resolved` to close
a conversation. Set `dailyLimit` on the conversation update endpoint to
override the global allowance for that contact; use `null` to restore the
global default or `0` to disable AI replies for that contact. A failed OpenAI
request leaves the conversation open in AI mode so the customer can retry.

Monitor:

```bash
php artisan queue:failed
php artisan route:list --path=support
tail -f storage/logs/laravel.log
```

Twilio delivery states (`queued`, `sent`, `delivered`, `read`, and `failed`) are
recorded through the status callback. Token totals and daily AI replies are
available from the admin metrics endpoint.
