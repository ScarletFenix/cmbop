<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Unsubscribe from marketing emails</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem 1rem; }
        .card { max-width: 28rem; margin: 3rem auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(15,23,42,.08); }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { color: #475569; line-height: 1.5; }
        button { background: #0f172a; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.1rem; font-size: 1rem; cursor: pointer; }
        button:hover { background: #1e293b; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Unsubscribe from marketing emails?</h1>
        <p>We’ll stop sending campaign and promotional updates to <strong>{{ $user->email }}</strong>. Order, payment, and security emails stay on.</p>
        <form method="post" action="{{ $confirmAction }}">
            @csrf
            <button type="submit">Unsubscribe</button>
        </form>
    </div>
</body>
</html>
