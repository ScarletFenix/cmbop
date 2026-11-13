@php
    $user = auth()->user();
    $layout = $signed ? 'layouts.guest' : \App\Support\PortalShell::layout($user);
@endphp
@if($signed)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New-site digest preferences</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:720px;">
@else
@extends($layout)
@section('title', 'New-site digest preferences')
@section('content')
<div class="{{ \App\Support\PortalShell::contentClass($user) }}" style="max-width:720px;">
@endif

    <h1 class="h4 fw-semibold mb-2" style="color:#1a585e;">New-site digest</h1>
    <p class="text-muted">Weekly email of newly listed sites in the niche and country pairs you have paid for. You can turn the topic off or edit pairs.</p>

    @if($signed)
        @include('partials.session-flash')
    @endif

    <form method="post" action="{{ $formAction }}">
        @csrf
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <label class="d-flex justify-content-between align-items-center">
                    <span>Send me the weekly new-site digest</span>
                    <input type="checkbox" name="digest_enabled" value="1" @checked($topicOn)>
                </label>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Your niche + country pairs</h2>
                <p class="small text-muted">Pairs come from paid placements. Uncheck to stop matching that pair. This is not a cross-product of niches and countries.</p>
                @forelse($pairs as $pair)
                    <label class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>{{ $pair->niche }} · {{ strtoupper($pair->country_code) }} <span class="text-muted small">({{ $pair->source }})</span></span>
                        <input type="checkbox" name="enabled_ids[]" value="{{ $pair->id }}" @checked($pair->enabled)>
                    </label>
                @empty
                    <p class="text-muted mb-0">No pairs yet. Pairs appear after a paid, non-cancelled placement.</p>
                @endforelse

                <div class="row g-2 mt-3">
                    <div class="col-md-6">
                        <label class="form-label" for="add_niche">Add niche</label>
                        <input class="form-control" id="add_niche" name="add_niche" maxlength="120">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="add_country">Country code</label>
                        <input class="form-control" id="add_country" name="add_country" maxlength="2" placeholder="gb">
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save</button>
        @if(!$signed)
            <a href="{{ route('profile.notifications') }}" class="btn btn-outline-secondary btn-sm">Notification preferences</a>
        @endif
    </form>
</div>
@if($signed)
</body>
</html>
@else
@endsection
@endif
