<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Promotions preview — SEOLinkBuildings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="{{ asset('assets/vendor/bootstrap-5.3.0/bootstrap.min.css') }}?v={{ @filemtime(public_path('assets/vendor/bootstrap-5.3.0/bootstrap.min.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-icons.css') }}?v={{ @filemtime(public_path('assets/css/slb-icons.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/promotions.css') }}" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="alert alert-warning" role="status">
        Preview only — impressions are not counted. Audience: <strong>{{ $audience }}</strong>
        · Placement: <strong>{{ $placement }}</strong>
    </div>
    @include('components.site-announcements', ['audience' => $audience, 'track' => false])
    @include('components.ad-banners', ['placement' => $placement, 'audience' => $audience, 'track' => false])
    <p class="small text-muted mt-4">
        <a href="{{ staff_route('promotions.index') }}">Back to Promotions</a>
    </p>
</div>
</body>
</html>
