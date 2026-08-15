<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>You’ve been unsubscribed</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem 1rem; }
        .card { max-width: 28rem; margin: 3rem auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(15,23,42,.08); }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { color: #475569; line-height: 1.5; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <h1>You’ve been unsubscribed</h1>
        <p><strong>{{ $user->email }}</strong> will no longer receive marketing emails from {{ $brand }}.</p>
        <p>You can turn marketing emails back on anytime in your notification preferences after you sign in.</p>
    </div>
</body>
</html>
