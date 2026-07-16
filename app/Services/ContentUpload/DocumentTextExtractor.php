<?php

namespace App\Services\ContentUpload;

use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

class DocumentTextExtractor
{
    /**
     * @return array{ok:bool, text:?string, html:?string, word_count:int, error_code:?string, error_message:?string}
     */
    public function extract(string $absolutePath, string $extension): array
    {
        $extension = strtolower(ltrim($extension, '.'));

        try {
            return match ($extension) {
                'docx' => $this->extractDocx($absolutePath),
                'doc' => $this->extractDoc($absolutePath),
                'pdf' => $this->extractPdf($absolutePath),
                default => $this->fail('unsupported_file', 'Unsupported file type. Please upload a .docx document.'),
            };
        } catch (\Throwable $e) {
            return $this->fail('corrupted_file', 'This document appears corrupted or unreadable. Please re-export it as .docx and try again.');
        }
    }

    /**
     * @return array{ok:bool, text:?string, html:?string, word_count:int, error_code:?string, error_message:?string}
     */
    protected function extractDocx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            return $this->fail('unsupported_file', 'Document processing is unavailable on this server.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return $this->fail('corrupted_file', 'Unable to open the Word document. Please re-save as .docx and upload again.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || trim($xml) === '') {
            return $this->fail('empty_document', 'This document appears empty. Please upload an article with content.');
        }

        // Preserve paragraphs for preview / quality checks
        $withBreaks = preg_replace('/<\/w:p>/', "\n\n", $xml) ?? $xml;
        $withBreaks = preg_replace('/<\/w:tr>/', "\n", $withBreaks) ?? $withBreaks;
        $withBreaks = preg_replace('/<w:tab[^>]*\/>/', "\t", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return $this->fail('empty_document', 'No readable text was found in this document.');
        }

        $html = $this->textToPreviewHtml($text);
        $words = $this->countWords($text);

        return [
            'ok' => true,
            'text' => $text,
            'html' => $html,
            'word_count' => $words,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * Legacy .doc is optional — attempt a best-effort binary string scrape.
     *
     * @return array{ok:bool, text:?string, html:?string, word_count:int, error_code:?string, error_message:?string}
     */
    protected function extractDoc(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $this->fail('corrupted_file', 'Unable to read this .doc file. Please convert it to .docx and upload again.');
        }

        // Prefer UTF-16LE text blocks common in older Word binaries
        $utf16 = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        $candidate = is_string($utf16) ? $utf16 : '';
        $candidate = preg_replace('/[^\P{C}\n\t]+/u', ' ', $candidate) ?? '';
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate) ?? '');

        if ($this->countWords($candidate) < 30) {
            // Fallback: printable ASCII/UTF-8 strings
            preg_match_all('/[\x20-\x7E]{4,}/', $raw, $matches);
            $candidate = trim(implode(' ', $matches[0] ?? []));
        }

        if ($this->countWords($candidate) < 20) {
            return $this->fail(
                'unsupported_file',
                'We could not reliably extract text from this .doc file. Please upload a .docx document instead.'
            );
        }

        $html = $this->textToPreviewHtml($candidate);

        return [
            'ok' => true,
            'text' => $candidate,
            'html' => $html,
            'word_count' => $this->countWords($candidate),
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @return array{ok:bool, text:?string, html:?string, word_count:int, error_code:?string, error_message:?string}
     */
    protected function extractPdf(string $path): array
    {
        if (!class_exists(PdfParser::class)) {
            return $this->fail('unsupported_file', 'PDF extraction is unavailable. Please upload a .docx document.');
        }

        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        $text = trim((string) $pdf->getText());

        if ($text === '') {
            return $this->fail('empty_document', 'No readable text was found in this PDF. Please upload a .docx document.');
        }

        $html = $this->textToPreviewHtml($text);

        return [
            'ok' => true,
            'text' => $text,
            'html' => $html,
            'word_count' => $this->countWords($text),
            'error_code' => null,
            'error_message' => null,
        ];
    }

    protected function textToPreviewHtml(string $text): string
    {
        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [$text];
        $html = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $html .= '<p>' . e($p) . '</p>';
        }

        return $html !== '' ? $html : '<p>' . e($text) . '</p>';
    }

    protected function countWords(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * @return array{ok:bool, text:?string, html:?string, word_count:int, error_code:?string, error_message:?string}
     */
    protected function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'text' => null,
            'html' => null,
            'word_count' => 0,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
