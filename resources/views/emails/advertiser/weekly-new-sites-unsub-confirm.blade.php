<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribe</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#5c6b70;padding:24px;">
    <h1 style="color:#1a585e;font-size:20px;">Unsubscribe from the new-site digest</h1>
    <p>This stops the weekly new-site email only. Other account emails are unchanged.</p>
    <form method="post" action="{{ $confirmAction }}">
        @csrf
        <button type="submit" style="background:#1a585e;color:#ffffff;border:0;padding:10px 16px;">Unsubscribe</button>
    </form>
</body>
</html>
