<div class="d-flex flex-wrap gap-2 justify-content-center align-items-center">
    @if($uploadsEnabled)
        @include('advertiser.partials.upload-article-button', ['uploadsEnabled' => true])
    @endif
    <a href="{{ route('advertiser.catalog') }}" class="btn btn-link btn-sm library-browse-link">
        Browse publishers
    </a>
</div>
<p class="small mb-0 mt-2">
    <a href="{{ route('advertiser.wizard.start') }}" class="text-muted">Guided placement</a>
</p>
