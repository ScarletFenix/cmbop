@php
    $logoUrl = $mailTheme->logoUrl ?? \App\Support\TransactionalMailTheme::LOGO_URL;
    $siteUrl = $mailTheme->siteUrl ?? (function_exists('mail_brand_website_url') ? mail_brand_website_url() : 'https://seolinkbuildings.com');
    $brandName = $mailTheme->brandName ?? (function_exists('mail_brand_name') ? mail_brand_name() : 'SEOLinkBuildings');
@endphp
<tr>
<td class="mail-header-bar" align="center" bgcolor="#ffffff" style="background-color:#ffffff;border-bottom:3px solid #3faeb2;padding:18px 24px;">
<a href="{{ $siteUrl }}" style="display:inline-block;text-decoration:none;border:0;">
<img src="{{ $logoUrl }}" width="200" alt="{{ $brandName }}" style="display:block;border:0;outline:none;text-decoration:none;max-width:200px;height:auto;background:transparent;">
</a>
</td>
</tr>
