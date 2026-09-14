<?php

namespace Database\Seeders;

use App\Models\SupportKnowledgeArticle;
use Illuminate\Database\Seeder;

class SupportKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->articles() as $article) {
            SupportKnowledgeArticle::firstOrCreate(
                ['public_id' => $article['public_id']],
                array_merge($article, [
                    'status' => 'published',
                    'version' => 1,
                    'published_at' => now(),
                ])
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function articles(): array
    {
        return [
            [
                'public_id' => '8f5be5d0-8147-4fbb-b741-69f403d21835',
                'title' => 'What Almax Predictions provides',
                'question' => 'What is Almax Predictions and what can I get here?',
                'answer' => 'Almax provides football prediction content, including public content and time-limited VIP packages. Available packages, prices, odds types, and deadlines can change, so check the current packages before quoting them. Predictions are informational and no outcome or return is guaranteed.',
                'keywords' => ['almax', 'about', 'service', 'predictions', 'football', 'vip', 'packages'],
                'locale' => 'en',
            ],
            [
                'public_id' => 'bd780609-8840-424f-8ef1-ab552d68f908',
                'title' => 'How to buy a VIP package',
                'question' => 'How do I subscribe to an Almax VIP package?',
                'answer' => 'Sign in to your Almax account, choose a package that is currently open, select MTN Mobile Money or Airtel Money, and enter the number that should receive the payment prompt. Approve the prompt on that phone. Access activates only after payment is confirmed.',
                'keywords' => ['buy', 'subscribe', 'package', 'vip', 'mtn', 'airtel', 'payment', 'prompt'],
                'locale' => 'en',
            ],
            [
                'public_id' => '2754e8d8-2c69-409f-a77c-92fe1611620c',
                'title' => 'Pending mobile-money payment',
                'question' => 'What should I do when my payment is pending?',
                'answer' => 'Do not pay a second time while the first request is being checked. Keep the payment or transaction reference. Almax support can check the linked account record and, when available, the provider status. A copied SMS or screenshot alone cannot confirm payment.',
                'keywords' => ['payment', 'pending', 'mobile money', 'transaction', 'reference', 'mtn', 'airtel'],
                'locale' => 'en',
            ],
            [
                'public_id' => '2df2e44c-275c-4cef-9107-fe539693d9b2',
                'title' => 'Paid but access is inactive',
                'question' => 'I paid but my subscription or betslip is not active.',
                'answer' => 'Check the payment and subscription attached to the customer’s linked account. If payment is confirmed but access remains inactive, advise the customer not to pay again and escalate the case for reconciliation. Never send another customer’s betslip or expose paid content without verified active access.',
                'keywords' => ['paid', 'inactive', 'access', 'betslip', 'subscription', 'reconcile'],
                'locale' => 'en',
            ],
            [
                'public_id' => 'd094daf5-f308-471f-a3fa-5ec84fd401fc',
                'title' => 'Receipts',
                'question' => 'How can I get my Almax payment receipt?',
                'answer' => 'A secure temporary receipt link can be issued only for a confirmed payment belonging to the linked Almax account. Ask for the payment reference when it is available, but never ask for a PIN, OTP, or password.',
                'keywords' => ['receipt', 'proof', 'confirmed', 'payment', 'reference'],
                'locale' => 'en',
            ],
            [
                'public_id' => '05ad48f8-2767-4b65-a083-9b5477de91f5',
                'title' => 'WhatsApp number is not linked',
                'question' => 'Why can support not find my Almax account?',
                'answer' => 'WhatsApp support can access customer-specific records only when the WhatsApp phone number matches a registered Almax account. Do not request a password or OTP. Escalate to human support when the account cannot be linked safely.',
                'keywords' => ['account', 'phone', 'whatsapp', 'linked', 'login', 'not found'],
                'locale' => 'en',
            ],
            [
                'public_id' => '62fc0ca8-a528-41e0-b751-5c120f88841a',
                'title' => 'Prediction responsibility',
                'question' => 'Are Almax predictions guaranteed to win?',
                'answer' => 'No. Football outcomes are uncertain and Almax predictions do not guarantee a win, return, or fixed result. Customers should make their own decisions and use prediction content responsibly.',
                'keywords' => ['guarantee', 'win', 'winning', 'sure', 'fixed', 'risk', 'responsible'],
                'locale' => 'en',
            ],
        ];
    }
}
