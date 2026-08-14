{{-- Catalog-style list pager. Always shows "Showing X–Y of Z" when total > 0,
     including page 1 of 1. Prev / pills / Next stay visible (disabled on a
     single page). Catalog itself still hides its footer when lastPage() <= 1. --}}
@php
    $paginator = $paginator ?? null;
    $noun = $noun ?? 'result';
    $ariaLabel = $ariaLabel ?? 'Pagination';
    $total = $paginator ? (int) $paginator->total() : 0;
@endphp
@if($paginator && $total > 0)
    <div class="catalog-pagination">
        <p class="catalog-pagination__meta">
            Showing
            <strong>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</strong>
            of <strong>{{ number_format($total) }}</strong>
            {{ \Illuminate\Support\Str::plural($noun, $total) }}
            <span class="catalog-pagination__page-label" aria-hidden="true">
                · Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            </span>
        </p>
        <div class="catalog-pagination__links">
            {{ $paginator->onEachSide(1)->links('advertiser.partials.catalog-pagination-links', ['ariaLabel' => $ariaLabel]) }}
        </div>
    </div>
@endif
