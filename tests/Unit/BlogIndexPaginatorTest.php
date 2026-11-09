<?php

namespace Tests\Unit;

use App\Support\BlogIndexPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class BlogIndexPaginatorTest extends TestCase
{
    public function test_page_one_url_omits_page_query_and_keeps_other_params(): void
    {
        $inner = new LengthAwarePaginator(collect(['a']), 25, 12, 2, [
            'path' => 'http://localhost/blog',
            'pageName' => 'page',
        ]);
        $paginator = BlogIndexPaginator::wrap($inner, ['utm_source' => 'gsc']);

        $this->assertSame('http://localhost/blog?utm_source=gsc', $paginator->url(1));
        $this->assertSame('http://localhost/blog?utm_source=gsc&page=2', $paginator->url(2));
        $this->assertSame('http://localhost/blog?utm_source=gsc', $paginator->previousPageUrl());
    }

    public function test_without_page_one_query_strips_only_page_one(): void
    {
        $this->assertSame(
            'https://seolinkbuildings.com/blog',
            BlogIndexPaginator::withoutPageOneQuery('https://seolinkbuildings.com/blog?page=1')
        );
        $this->assertSame(
            'https://seolinkbuildings.com/blog?utm_source=gsc',
            BlogIndexPaginator::withoutPageOneQuery('https://seolinkbuildings.com/blog?page=1&utm_source=gsc')
        );
        $this->assertSame(
            'https://seolinkbuildings.com/blog?page=2',
            BlogIndexPaginator::withoutPageOneQuery('https://seolinkbuildings.com/blog?page=2')
        );
    }
}
