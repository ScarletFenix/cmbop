@props(['mailTheme' => null])
@php
    $mailTheme = $mailTheme
        ?? (app()->bound('transactional.mailTheme') ? app('transactional.mailTheme') : null);
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
@include('emails.partials.mail-css')
{!! $head ?? '' !!}
</head>
<body class="mail-wrapper" style="margin:0;padding:0;width:100%;background-color:#f4fbfb;color:#334155;font-family:Arial, Helvetica, sans-serif;">
@include('emails.partials.mail-preheader')
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;background-color:#f4fbfb;margin:0;padding:0;">
<tr>
<td align="center" style="padding:16px 8px;">
<table class="content mail-shell" width="600" cellpadding="0" cellspacing="0" role="presentation" style="width:600px;max-width:600px;margin:0 auto;">
{!! $header ?? '' !!}

<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;background-color:#f7fafb;">
<table class="inner-body mail-body-card" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation" style="width:600px;max-width:600px;background-color:#f7fafb;">
<tr>
<td class="content-cell mail-pad{{ !empty($mailTheme?->hideFirstBodyHeading) ? ' mail-has-hero' : '' }}" style="padding:24px 28px 16px 28px;">
@include('emails.partials.mail-hero')
@include('emails.partials.mail-progress')
<div class="mail-body-copy">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
</div>
{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
