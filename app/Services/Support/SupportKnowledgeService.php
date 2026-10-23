<?php

namespace App\Services\Support;

use App\Models\SupportKnowledgeArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class SupportKnowledgeService
{
    private const STOP_WORDS = [
        'about', 'after', 'again', 'also', 'are', 'been', 'being', 'but', 'can',
        'could', 'did', 'does', 'for', 'from', 'has', 'have', 'help', 'here', 'how',
        'into', 'just', 'need', 'not', 'our', 'please', 'should', 'that', 'the',
        'their', 'there', 'these', 'they', 'this', 'want', 'was', 'what', 'when',
        'where', 'which', 'with', 'would', 'you', 'your',
    ];

    public function systemContext(): string
    {
        $path = (string) config('support.system_context_path', resource_path('support/almax.md'));

        if ($path !== '' && File::isFile($path)) {
            $context = trim(File::get($path));
            if ($context !== '') {
                return $context;
            }
        }

        return 'Almax Predictions provides football prediction content and customer subscription support.';
    }

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

    /**
     * Return an approved answer only when local matching is strong enough to
     * avoid guessing. Ambiguous questions continue to the model and tools.
     *
     * @return array{article:SupportKnowledgeArticle,confidence:float}|null
     */
    public function approvedAnswerFor(string $message): ?array
    {
        $normalized = $this->normalize($message);
        $queryTerms = $this->terms($message);
        if ($normalized === '' || $queryTerms->isEmpty()) {
            return null;
        }

        $best = SupportKnowledgeArticle::where('status', 'published')
            ->get()
            ->map(function (SupportKnowledgeArticle $article) use ($normalized, $queryTerms): array {
                $question = $this->normalize($article->question);
                $title = $this->normalize($article->title);
                if ($normalized === $question || $normalized === $title) {
                    return ['article' => $article, 'confidence' => 1.0];
                }

                $articleTerms = $this->terms(implode(' ', [
                    $article->title,
                    $article->question,
                    implode(' ', $article->keywords ?? []),
                ]));
                $overlap = $queryTerms->intersect($articleTerms)->count();
                $queryCoverage = $overlap / max(1, $queryTerms->count());
                $questionCoverage = $overlap / max(1, $this->terms($article->question)->count());
                $keywordHits = collect($article->keywords ?? [])->filter(function ($keyword) use ($normalized): bool {
                    $needle = $this->normalize((string) $keyword);

                    return $needle !== '' && str_contains(" {$normalized} ", " {$needle} ");
                })->count();

                $confidence = 0.0;
                if ($keywordHits >= 2 && $overlap >= 2) {
                    $confidence = min(0.96, 0.78 + ($keywordHits * 0.03));
                } elseif ($overlap >= 3 && $queryCoverage >= 0.65) {
                    $confidence = 0.80;
                } elseif ($overlap >= 2 && $queryCoverage >= 0.80 && $questionCoverage >= 0.35) {
                    $confidence = 0.76;
                }

                return ['article' => $article, 'confidence' => $confidence];
            })
            ->sortByDesc('confidence')
            ->first();

        return ($best['confidence'] ?? 0) >= (float) config('support.knowledge_fast_path_confidence', 0.76)
            ? $best
            : null;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? ''
        ));
    }

    /** @return Collection<int, string> */
    private function terms(string $value): Collection
    {
        return collect(explode(' ', $this->normalize($value)))
            ->filter(fn ($term) => mb_strlen($term) >= 3 && ! in_array($term, self::STOP_WORDS, true))
            ->unique()
            ->values();
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
