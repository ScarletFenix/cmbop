<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied — SEOLinkBuildings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/brand-colors.css') }}" rel="stylesheet">
    <link href="{{ asset('css/button-system.css') }}" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; background: #f8fafc; font-family: Poppins, system-ui, sans-serif; }
        .error-card { max-width: 480px; margin: auto; text-align: center; padding: 2rem; }
        .error-code { font-size: 4rem; font-weight: 700; color: #0b6266; line-height: 1; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-code">403</div>
        <h1 class="h4 mt-3 mb-2">Access denied</h1>
        <p class="text-muted mb-4">You don’t have permission to view this page.</p>
        <a href="{{ url('/') }}" class="btn btn-primary">Back to home</a>
    </div>
</body>
</html>
