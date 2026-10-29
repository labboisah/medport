<?php

namespace App\Services;

/** Structural inspection of uploads. This is NOT an anti-malware engine; plug one in before any real use. */
class DocumentInspector
{
    private const ALLOWED = ['application/pdf', 'image/png', 'image/jpeg'];

    /** @return array{ok: bool, mime: ?string, reason: ?string} */
    public static function inspect(string $path, int $maxBytes): array
    {
        $size = filesize($path);
        if ($size === false || $size === 0 || $size > $maxBytes) {
            return ['ok' => false, 'mime' => null, 'reason' => 'size'];
        }
        // The type comes from file content; client-supplied name and MIME are ignored.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, self::ALLOWED, true)) {
            return ['ok' => false, 'mime' => $mime ?: null, 'reason' => 'type'];
        }
        if ($mime === 'application/pdf') {
            $body = file_get_contents($path);
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b#', $body)) {
                return ['ok' => false, 'mime' => $mime, 'reason' => 'active_content'];
            }
        }

        return ['ok' => true, 'mime' => $mime, 'reason' => null];
    }
}
