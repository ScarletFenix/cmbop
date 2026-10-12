<?php

/**
 * One-shot vendor: Lucide SVGs + slb-icons.css (no runtime CDN).
 */
$root = dirname(__DIR__);
$lucideDir = $root.'/public/assets/icons/lucide';
$cssPath = $root.'/public/assets/css/slb-icons.css';

$faToLucide = [
    'plus' => 'plus',
    'plus-circle' => 'circle-plus',
    'minus' => 'minus',
    'trash' => 'trash-2',
    'save' => 'save',
    'copy' => 'copy',
    'clone' => 'copy',
    'download' => 'download',
    'upload' => 'upload',
    'filter' => 'list-filter',
    'filter-circle-xmark' => 'filter-x',
    'ban' => 'ban',
    'undo' => 'undo-2',
    'play' => 'play',
    'circle-play' => 'circle-play',
    'pause' => 'pause',
    'shuffle' => 'shuffle',
    'key' => 'key',
    'edit' => 'pencil',
    'pencil' => 'pencil',
    'pen' => 'pencil',
    'pen-to-square' => 'square-pen',
    'user-edit' => 'user-pen',
    'user-pen' => 'user-pen',
    'check' => 'check',
    'check-circle' => 'circle-check',
    'circle-check' => 'circle-check',
    'check-double' => 'check-check',
    'times' => 'x',
    'xmark' => 'x',
    'xmark-circle' => 'circle-x',
    'circle-exclamation' => 'circle-alert',
    'exclamation-circle' => 'circle-alert',
    'triangle-exclamation' => 'triangle-alert',
    'exclamation-triangle' => 'triangle-alert',
    'circle-info' => 'info',
    'info-circle' => 'info',
    'circle-question' => 'circle-help',
    'question-circle' => 'circle-help',
    'spinner' => 'loader-circle',
    'tachometer-alt' => 'layout-dashboard',
    'tachometer' => 'layout-dashboard',
    'house' => 'house',
    'list' => 'rows-3',
    'layer-group' => 'layers',
    'store' => 'store',
    'tasks' => 'list-checks',
    'folder-open' => 'folder-open',
    'table' => 'table',
    'blog' => 'newspaper',
    'newspaper-o' => 'newspaper',
    'book' => 'book-open',
    'address-book' => 'contact',
    'bullhorn' => 'megaphone',
    'bell' => 'bell',
    'life-ring' => 'life-buoy',
    'compass' => 'compass',
    'tools' => 'wrench',
    'screwdriver-wrench' => 'wrench',
    'sign-out-alt' => 'log-out',
    'search' => 'search',
    'magnifying-glass' => 'search',
    'shopping-cart' => 'shopping-bag',
    'cart-plus' => 'shopping-bag',
    'shopping-bag' => 'shopping-bag',
    'wallet' => 'wallet',
    'credit-card' => 'credit-card',
    'coins' => 'coins',
    'money-bill' => 'banknote',
    'money-bill-wave' => 'banknote',
    'euro-sign' => 'euro',
    'file-invoice' => 'receipt',
    'file-invoice-dollar' => 'receipt',
    'receipt' => 'receipt',
    'money-check-dollar' => 'receipt',
    'calculator' => 'calculator',
    'percent' => 'percent',
    'tag' => 'tag',
    'tags' => 'tags',
    'gift' => 'gift',
    'university' => 'landmark',
    'user' => 'user',
    'user-circle' => 'circle-user',
    'users' => 'users',
    'user-plus' => 'user-plus',
    'user-check' => 'user-check',
    'comments' => 'message-circle',
    'message' => 'message-circle',
    'paper-plane' => 'send',
    'envelope' => 'mail',
    'envelope-open-text' => 'mail-open',
    'envelope-circle-check' => 'mail-check',
    'inbox' => 'inbox',
    'lock' => 'lock',
    'unlock' => 'lock-open',
    'shield-alt' => 'shield',
    'star' => 'star',
    'star-half-stroke' => 'star-half',
    'heart' => 'heart',
    'bolt' => 'zap',
    'lightbulb' => 'lightbulb',
    'flag' => 'flag',
    'bullseye' => 'target',
    'hourglass-half' => 'hourglass',
    'globe' => 'globe',
    'link' => 'link',
    'image' => 'image',
    'camera' => 'camera',
    'file-csv' => 'file-spreadsheet',
    'file-word' => 'file-type',
    'file-alt' => 'file-text',
    'file-lines' => 'file-text',
    'file-text' => 'file-text',
    'file-text-o' => 'file-text',
    'clipboard-check' => 'clipboard-check',
    'clipboard-list' => 'clipboard-list',
    'hashtag' => 'hash',
    'archive' => 'archive',
    'box-archive' => 'archive',
    'box-open' => 'package-open',
    'building' => 'building-2',
    'id-card' => 'id-card',
    'gavel' => 'gavel',
    'server' => 'server',
    'ruler-combined' => 'ruler',
    'chart-line' => 'trending-up',
    'chart-pie' => 'chart-pie',
    'chart-area' => 'chart-area',
    'chart-bar' => 'chart-column',
    'clock' => 'clock',
    'clock-o' => 'clock',
    'history' => 'history',
    'clock-rotate-left' => 'history',
    'calendar' => 'calendar',
    'calendar-alt' => 'calendar',
    'arrow-right' => 'arrow-right',
    'arrow-left' => 'arrow-left',
    'arrow-up' => 'arrow-up',
    'chevron-down' => 'chevron-down',
    'chevron-up' => 'chevron-up',
    'chevron-left' => 'chevron-left',
    'chevron-right' => 'chevron-right',
    'external-link' => 'external-link',
    'arrow-up-right-from-square' => 'external-link',
    'exchange' => 'arrow-left-right',
    'exchange-alt' => 'arrow-left-right',
    'share-nodes' => 'share-2',
    'share-alt' => 'share-2',
    'eye' => 'eye',
    'eye-slash' => 'eye-off',
    'refresh' => 'refresh-cw',
    'sync' => 'refresh-cw',
    'redo' => 'refresh-cw',
    'rotate-right' => 'refresh-cw',
];

$brandFa = [
    'bitcoin' => 'brands/bitcoin.svg',
    'paypal' => 'brands/paypal.svg',
    'cc-visa' => 'brands/cc-visa.svg',
    'cc-mastercard' => 'brands/cc-mastercard.svg',
    'cc-amex' => 'brands/cc-amex.svg',
    'cc-discover' => 'brands/credit-card.svg',
    'cc-diners-club' => 'brands/credit-card.svg',
    'cc-jcb' => 'brands/credit-card.svg',
    'facebook' => 'brands/facebook.svg',
    'facebook-f' => 'brands/facebook.svg',
    'instagram' => 'brands/instagram.svg',
    'linkedin' => 'brands/linkedin.svg',
    'linkedin-in' => 'brands/linkedin.svg',
    'twitter' => 'brands/x.svg',
    'x-twitter' => 'brands/x.svg',
    'youtube' => 'brands/youtube.svg',
];

$needed = array_values(array_unique(array_values($faToLucide)));
$base = 'https://cdn.jsdelivr.net/npm/lucide-static@0.468.0/icons';

if (! is_dir($lucideDir)) {
    mkdir($lucideDir, 0755, true);
}

$failed = [];
foreach ($needed as $name) {
    $dest = $lucideDir.'/'.$name.'.svg';
    if (is_file($dest) && filesize($dest) > 40) {
        continue;
    }
    $svg = @file_get_contents($base.'/'.$name.'.svg');
    if ($svg === false || ! str_contains($svg, '<svg')) {
        $failed[] = $name;

        continue;
    }
    file_put_contents($dest, str_replace('currentColor', '#000', $svg));
}

if ($failed !== []) {
    fwrite(STDERR, "Missing Lucide icons: ".implode(', ', $failed)."\n");
    exit(1);
}

$lines = [];
$lines[] = '/* Local Lucide + brand icons. Replaces Font Awesome CDN. Do not load FA from a CDN. */';
$lines[] = '.fa,.fas,.far,.fal,.fab,.fa-solid,.fa-regular,.fa-brands,.fa-classic{';
$lines[] = '  --slb-icon:url("../icons/lucide/circle-help.svg");';
$lines[] = '  display:inline-block;width:1em;height:1em;flex:0 0 1em;box-sizing:border-box;';
$lines[] = '  font-style:normal;font-variant:normal;line-height:1;vertical-align:middle;';
$lines[] = '  background-color:currentColor;';
$lines[] = '  -webkit-mask:var(--slb-icon) center/contain no-repeat;mask:var(--slb-icon) center/contain no-repeat;';
$lines[] = '  speak:never;';
$lines[] = '}';
$lines[] = '.fa:before,.fas:before,.far:before,.fal:before,.fab:before,.fa-solid:before,.fa-regular:before,.fa-brands:before{content:none!important}';
$lines[] = '.fa-fw{width:1.25em}';
$lines[] = '.fa-sm{width:.875em;height:.875em}';
$lines[] = '.fa-lg{width:1.333em;height:1.333em}';
$lines[] = '.fa-xl{width:1.5em;height:1.5em}';
$lines[] = '.fa-2x{width:2em;height:2em}';
$lines[] = '.fa-3x{width:3em;height:3em}';
$lines[] = '@keyframes slb-icon-spin{to{transform:rotate(360deg)}}';
$lines[] = '@keyframes slb-icon-draw{from{stroke-dashoffset:64}to{stroke-dashoffset:0}}';
$lines[] = '.fa-spin{-webkit-animation:slb-icon-spin 1.2s linear infinite;animation:slb-icon-spin 1.2s linear infinite}';

foreach ($faToLucide as $fa => $lucide) {
    $url = '../icons/lucide/'.$lucide.'.svg';
    $lines[] = '.fa-'.$fa.',.fa-solid.fa-'.$fa.',.fa-regular.fa-'.$fa.',.fas.fa-'.$fa.',.far.fa-'.$fa.'{--slb-icon:url("'.$url.'")}';
}

foreach ($brandFa as $fa => $file) {
    $url = '../icons/'.$file;
    $lines[] = '.fa-'.$fa.',.fab.fa-'.$fa.',.fa-brands.fa-'.$fa.'{--slb-icon:url("'.$url.'")}';
}

$lines[] = '.fa.slb-icon-svg,.fas.slb-icon-svg,.far.slb-icon-svg,.fal.slb-icon-svg,.fab.slb-icon-svg,.fa-solid.slb-icon-svg,.fa-regular.slb-icon-svg,.fa-brands.slb-icon-svg,.fa-classic.slb-icon-svg{background:none!important;-webkit-mask:none!important;mask:none!important}';
$lines[] = '.fa.slb-icon-svg svg,.fas.slb-icon-svg svg,.far.slb-icon-svg svg,.fab.slb-icon-svg svg,.fa-solid.slb-icon-svg svg,.fa-regular.slb-icon-svg svg,.fa-brands.slb-icon-svg svg{width:100%;height:100%;display:block;overflow:visible;stroke:currentColor;fill:none}';
$lines[] = '.m-draw svg path,.m-draw svg circle,.m-draw svg line,.m-draw svg polyline,.m-draw svg rect,.m-draw svg ellipse,.m-draw svg polygon{stroke:currentColor;fill:none;stroke-dasharray:64;stroke-dashoffset:0}';
$lines[] = '.m-draw:hover svg path,.m-draw:hover svg circle,.m-draw:hover svg line,.m-draw:hover svg polyline,.m-draw:hover svg rect,.m-draw:hover svg ellipse,.m-draw:hover svg polygon{-webkit-animation:slb-icon-draw .85s ease both;animation:slb-icon-draw .85s ease both}';
$lines[] = '@media (prefers-reduced-motion:reduce){.m-draw:hover svg path,.m-draw:hover svg circle,.m-draw:hover svg line,.m-draw:hover svg polyline,.m-draw:hover svg rect,.m-draw:hover svg ellipse,.m-draw:hover svg polygon{animation:none}}';

file_put_contents($cssPath, implode("\n", $lines)."\n");
echo 'Wrote '.count($needed).' Lucide SVGs and '.$cssPath."\n";
