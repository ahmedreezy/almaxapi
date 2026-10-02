<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportKnowledgeArticle;
use App\Models\SupportMessage;

class SupportFastReplyService
{
    public function __construct(
        private readonly SupportToolExecutor $tools,
        private readonly SupportKnowledgeService $knowledge,
    ) {}

    /**
     * Resolve safe, common requests without paying for one or more model round trips.
     *
     * @return array<string, mixed>|null
     */
    public function respond(SupportConversation $conversation, SupportMessage $incoming): ?array
    {
        $message = mb_strtolower(trim($incoming->body));
        if ($this->isPackageDiscoveryRequest($message)) {
            return $this->packageReply($conversation, $incoming);
        }

        $match = $this->knowledge->approvedAnswerFor($incoming->body);
        if (! $match) {
            return null;
        }

        $article = $match['article'];

        return [
            'reply' => trim($article->answer),
            'language' => $article->locale === 'lg' ? 'lg' : 'en',
            'category' => $this->categoryFor($article),
            'sentiment' => 'neutral',
            'priority' => 'normal',
            'action' => 'answer',
            'summary' => 'Answered from approved support knowledge: '.$article->title,
            'resolved' => false,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'model' => 'approved-knowledge',
            'performance' => [
                'source' => 'knowledge_fast_path',
                'knowledge_article_id' => $article->public_id,
                'knowledge_confidence' => round((float) $match['confidence'], 2),
                'openai_rounds' => 0,
                'openai_ms' => 0,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function packageReply(SupportConversation $conversation, SupportMessage $incoming): ?array
    {
        $result = $this->tools->execute($conversation, $incoming, 'get_available_packages', []);
        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $packages = collect($result['packages'] ?? []);
        if ($packages->isEmpty()) {
            $reply = 'There are no packages currently open for purchase. Please check again later, and do not send money for a package that is not shown as available.';
            $summary = 'Customer asked about purchasing a package; none are currently available.';
        } else {
            $lines = $packages->map(function ($package): string {
                $name = trim((string) data_get($package, 'name', 'Package'));
                $price = number_format((float) data_get($package, 'price', 0), 0);
                $plan = ucfirst((string) data_get($package, 'plan_type', ''));
                $details = $plan !== '' ? " ({$plan})" : '';

                return "• {$name}{$details} — UGX {$price}";
            })->implode("\n");

            $reply = "These packages are currently available:\n{$lines}\n\nChoose the package on the Almax home page to continue with MTN Mobile Money or Airtel Money.";
            $summary = 'Customer asked what packages are currently available to purchase.';
        }

        return [
            'reply' => $reply,
            'language' => 'en',
            'category' => 'subscription',
            'sentiment' => 'neutral',
            'priority' => 'normal',
            'action' => 'answer',
            'summary' => $summary,
            'resolved' => false,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'model' => 'deterministic-fast-path',
            'performance' => [
                'source' => 'package_fast_path',
                'openai_rounds' => 0,
                'openai_ms' => 0,
            ],
        ];
    }

    private function categoryFor(SupportKnowledgeArticle $article): string
    {
        $text = mb_strtolower(implode(' ', [
            $article->title,
            $article->question,
            implode(' ', $article->keywords ?? []),
        ]));

        return match (true) {
            str_contains($text, 'payment'), str_contains($text, 'receipt') => 'payment',
            str_contains($text, 'package'), str_contains($text, 'subscription') => 'subscription',
            str_contains($text, 'account'), str_contains($text, 'login') => 'account',
            str_contains($text, 'prediction'), str_contains($text, 'win') => 'prediction_content',
            default => 'other',
        };
    }

    private function isPackageDiscoveryRequest(string $message): bool
    {
        if ($message === '' || preg_match(
            '/\b(my|active|current)\s+(package|plan|subscription)\b|\b(payment|paid|transaction|failed|charged|deducted|pending|receipt)\b/u',
            $message
        )) {
            return false;
        }

        $purchaseIntent = preg_match(
            '/\b(purchase|purchasing|buy|buying|subscribe|available|availability|price|prices|cost)\b/u',
            $message
        );
        $packageQuestion = preg_match(
            '/\b(which|what|show|list|see|find|have|offer)\b.{0,40}\b(packages?|plans?)\b|\b(packages?|plans?)\b.{0,40}\b(available|offered|prices?|cost)\b/u',
            $message
        );

        return (bool) ($purchaseIntent || $packageQuestion);
    }
}
