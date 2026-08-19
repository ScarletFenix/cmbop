<?php

namespace App\Support;

class ArticleDownload
{
    /**
     * Force a file download. Do not inline Word files in the browser.
     *
     * @return array<string, string>
     */
    public static function headers(string $filename, string $mime = ''): array
    {
        $safe = str_replace(["\r", "\n", '"'], '', basename($filename));
        if ($safe === '') {
            $safe = 'article.docx';
        }

        return [
            'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
