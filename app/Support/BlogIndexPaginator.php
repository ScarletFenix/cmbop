<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

/**
 * Public blog listing paginator that omits ?page=1 from generated URLs.
 */
class BlogIndexPaginator extends LengthAwarePaginator
{
    public static function wrap(LengthAwarePaginator $paginator, array $query = []): self
    {
        $wrapped = new self(
            $paginator->getCollection(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'pageName' => $paginator->getPageName(),
                'query' => $query,
            ]
        );
        $wrapped->fragment($paginator->fragment());

        return $wrapped;
    }

    /**
     * Drop a redundant ?page=1 (or &page=1) while keeping other query params.
     */
    public static function withoutPageOneQuery(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        if (! array_key_exists('page', $query) || (string) $query['page'] !== '1') {
            return $url;
        }

        unset($query['page']);

        $rebuilt = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt .= $parts['scheme'].'://'.$parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':'.$parts['port'];
            }
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?'.Arr::query($query);
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param  int  $page
     */
    public function url($page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        $parameters = $this->query;
        unset($parameters[$this->pageName]);
        if ($page > 1) {
            $parameters[$this->pageName] = $page;
        }

        $path = $this->path();
        if ($parameters === []) {
            return $path.$this->buildFragment();
        }

        return $path
            .(str_contains($path, '?') ? '&' : '?')
            .Arr::query($parameters)
            .$this->buildFragment();
    }
}
