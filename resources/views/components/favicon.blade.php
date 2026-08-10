<link rel="icon" href="{{ asset('assets/brand/web/favicon.svg') }}?v={{ @filemtime(public_path('assets/brand/web/favicon.svg')) ?: '1' }}" type="image/svg+xml">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/img/favicon-32.png') }}?v={{ @filemtime(public_path('assets/img/favicon-32.png')) ?: '1' }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('assets/img/favicon-16.png') }}?v={{ @filemtime(public_path('assets/img/favicon-16.png')) ?: '1' }}">
<link rel="apple-touch-icon" href="{{ asset('assets/img/apple-touch-icon.png') }}?v={{ @filemtime(public_path('assets/img/apple-touch-icon.png')) ?: '1' }}">
{{-- Browsers often auto-request /favicon.ico; keep a root copy in sync with the brand mark --}}
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v={{ @filemtime(public_path('favicon.ico')) ?: '1' }}" sizes="any">
<meta name="theme-color" content="#0b6266">
<meta name="msapplication-TileColor" content="#0b6266">
