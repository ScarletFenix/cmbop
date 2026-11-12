@php
    $mailTheme = $mailTheme
        ?? (app()->bound('transactional.mailTheme') ? app('transactional.mailTheme') : null)
        ?? \App\Support\TransactionalMailTheme::fromViewData(get_defined_vars());
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ $mailTheme->heading ?: config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
@include('emails.partials.mail-css')
</head>
<body class="mail-wrapper" style="margin:0;padding:0;width:100%;background-color:#f4fbfb;color:#334155;font-family:Arial, Helvetica, sans-serif;">
@include('emails.partials.mail-preheader')
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;background-color:#f4fbfb;margin:0;padding:0;">
<tr>
<td align="center" style="padding:16px 8px;">
<table class="content mail-shell" width="600" cellpadding="0" cellspacing="0" role="presentation" style="width:600px;max-width:600px;margin:0 auto;">
@include('emails.partials.mail-header-bar')
<tr>
<td class="body" width="100%" style="background-color:#f7fafb;">
<table class="inner-body mail-body-card" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation" style="width:600px;max-width:600px;background-color:#f7fafb;">
<tr>
<td class="content-cell mail-pad" style="padding:24px 28px 16px 28px;">
@include('emails.partials.mail-hero')
@include('emails.partials.mail-progress')
@yield('body')
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td>
@include('emails.partials.mail-footer-bar')
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
