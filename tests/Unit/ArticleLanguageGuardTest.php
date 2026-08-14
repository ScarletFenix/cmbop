<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ArticleLanguageGuard;
use PHPUnit\Framework\TestCase;

class ArticleLanguageGuardTest extends TestCase
{
    public function test_detection_ignores_tokens_past_the_scoring_cap(): void
    {
        $guard = new ArticleLanguageGuard;
        $head = implode(' ', array_fill(0, 15000, 'the'));

        $a = $guard->detect($head.' extrauniquetokenaaa');
        $b = $guard->detect($head.' extrauniquetokenbbb');

        $this->assertSame($a['language'], $b['language']);
        $this->assertSame($a['scores']['en'] ?? null, $b['scores']['en'] ?? null);
    }
}
