<?php

namespace App\Services\Support;

use App\Models\SupportKnowledgeArticle;
use Illuminate\Support\Collection;

class SupportKnowledgeService
{
    public function contextFor(string $message): string
    {
        $articles = SupportKnowledgeArticle::where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(100)
            ->get();

        $terms = collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($message)) ?: [])
            ->filter(fn ($term) => mb_strlen($term) >= 3)
            ->unique()
            ->values();

        $ranked = $articles->map(function (SupportKnowledgeArticle $article) use ($terms) {
            $haystack = mb_strtolower(implode(' ', [
                $article->title,
                $article->question,
                implode(' ', $article->keywords ?? []),
                $article->answer,
            ]));
            $score = $terms->sum(fn ($term) => substr_count($haystack, $term));

            return ['article' => $article, 'score' => $score];
        })->sortByDesc('score');

        $limit = max(1, (int) config('support.knowledge_articles', 8));
        $selected = $ranked->filter(fn ($row) => $row['score'] > 0)->take($limit);
        if ($selected->isEmpty()) {
            $selected = $ranked->take(min(3, $limit));
        }

        return $this->format($selected->pluck('article'));
    }

    /** @param Collection<int, SupportKnowledgeArticle> $articles */
    private function format(Collection $articles): string
    {
        if ($articles->isEmpty()) {
            return 'No approved knowledge articles are currently available.';
        }

        return $articles->map(fn (SupportKnowledgeArticle $article) => implode("\n", [
            "ARTICLE {$article->public_id} [{$article->locale}]",
            "Title: {$article->title}",
            "Question: {$article->question}",
            "Approved answer: {$article->answer}",
        ]))->implode("\n\n");
    }
}
