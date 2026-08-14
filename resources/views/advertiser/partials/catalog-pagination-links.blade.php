{{-- Catalog pager: icon Prev/Next + mobile current-page pill; numbered window on md+.
     Keep real <a class="page-link"> hrefs so live fetch and no-JS navigation both work.
     Always render (even on a single page) so Library/Orders can show a disabled
     Prev/Next chrome; Catalog still hides the parent footer when lastPage() <= 1. --}}
@php
    $ariaLabel = $ariaLabel ?? 'Catalog pages';
@endphp
    <nav aria-label="{{ $ariaLabel }}">
        {{-- Mobile: icon prev/next + current page pill --}}
        <ul class="pagination catalog-pagination__mobile d-flex d-md-none mb-0">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    </span>
                    <span class="visually-hidden">{{ __('pagination.previous') }}</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    </a>
                </li>
            @endif

            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link catalog-pagination__pill" aria-current="page">
                    {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
                </span>
            </li>

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </span>
                    <span class="visually-hidden">{{ __('pagination.next') }}</span>
                </li>
            @endif
        </ul>

        {{-- Desktop: icon prev/next + compact numbered window --}}
        <ul class="pagination catalog-pagination__desktop d-none d-md-flex mb-0">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                    <span class="page-link" aria-hidden="true">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    </span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    </a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                    <span class="page-link" aria-hidden="true">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </span>
                </li>
            @endif
        </ul>
    </nav>
