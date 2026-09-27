<?php
declare(strict_types=1);

/**
 * Secure file upload handling:
 *  - extension AND server-side MIME (finfo) whitelist
 *  - size limit
 *  - images re-encoded with GD (strips embedded payloads/EXIF, incl. GPS metadata)
 *  - random file names stored in private storage (never executed, served via file.php)
 */
final class Upload
{
    public const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    public const DOC_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    public const BIDDER_DOC_TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    /**
     * Normalize $_FILES[$field] into a list (supports multiple uploads).
     * @return array<int,array{name:string,tmp_name:string,error:int,size:int}>
     */
    public static function files(string $field): array
    {
        $f = $_FILES[$field] ?? null;
        if (!$f) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach ($f['name'] as $i => $n) {
            if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return $out;
    }

    /**
     * Validate and store an uploaded file.
     * @return array{path:string,original:string,mime:string,size:int}
     * @throws RuntimeException with a user-safe message
     */
    public static function store(array $file, string $subdir, array $allowed): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
                default => 'The file could not be uploaded.',
            });
        }
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new RuntimeException('Invalid upload.');
        }
        $maxBytes = ((int) config('app.max_upload_mb', 10)) * 1024 * 1024;
        if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) {
            throw new RuntimeException('File must be smaller than ' . config('app.max_upload_mb', 10) . ' MB.');
        }
        $original = mb_substr(preg_replace('/[^\p{L}\p{N}\s._()-]/u', '_', basename((string) $file['name'])) ?? 'file', 0, 200);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            throw new RuntimeException('File type not allowed. Allowed: ' . strtoupper(implode(', ', array_unique(array_keys($allowed)))) . '.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $okMimes = array_values($allowed);
        // Some servers report older Office formats generically.
        $aliases = ['application/zip' => ['docx', 'xlsx'], 'application/octet-stream' => ['doc', 'xls'], 'application/CDFV2' => ['doc', 'xls'], 'application/vnd.ms-office' => ['doc', 'xls']];
        $mimeOk = in_array($mime, $okMimes, true) && $allowed[$ext] === $mime
            || (isset($aliases[$mime]) && in_array($ext, $aliases[$mime], true));
        if (!$mimeOk) {
            throw new RuntimeException('The file content does not match its type. Please upload a valid ' . strtoupper($ext) . ' file.');
        }

        $dir = storage_path('uploads/' . trim($subdir, '/') . '/' . date('Y/m'));
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Storage folder is not writable.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $dest = $dir . '/' . $name;

        if (str_starts_with($mime, 'image/')) {
            if (@getimagesize($file['tmp_name']) === false) {
                throw new RuntimeException('Invalid image file.');
            }
            if (!self::reencodeImage($file['tmp_name'], $dest, $mime)) {
                if (!move_uploaded_file($file['tmp_name'], $dest) && !copy($file['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not save file.');
                }
            }
        } elseif ($mime === 'application/pdf') {
            $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 1024);
            if (!str_starts_with($head, '%PDF-')) {
                throw new RuntimeException('Invalid PDF file.');
            }
            if (!move_uploaded_file($file['tmp_name'], $dest) && !copy($file['tmp_name'], $dest)) {
                throw new RuntimeException('Could not save file.');
            }
        } else {
            if (!move_uploaded_file($file['tmp_name'], $dest) && !copy($file['tmp_name'], $dest)) {
                throw new RuntimeException('Could not save file.');
            }
        }
        @chmod($dest, 0640);
        $rel = 'uploads/' . trim($subdir, '/') . '/' . date('Y/m') . '/' . $name;
        return ['path' => $rel, 'original' => $original, 'mime' => $mime, 'size' => (int) filesize($dest)];
    }

    private static function reencodeImage(string $src, string $dest, string $mime): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if (!$img) {
            return false;
        }
        // Downscale very large images to max 2400px on the longest side.
        $w = imagesx($img); $h = imagesy($img); $max = 2400;
        if ($w > $max || $h > $max) {
            $r = min($max / $w, $max / $h);
            $scaled = imagescale($img, (int) ($w * $r), (int) ($h * $r));
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
            }
        }
        $ok = match ($mime) {
            'image/png' => imagepng($img, $dest, 6),
            'image/webp' => function_exists('imagewebp') && imagewebp($img, $dest, 85),
            default => imagejpeg($img, $dest, 85),
        };
        imagedestroy($img);
        return (bool) $ok;
    }

    /** Resolve a stored relative path to an absolute file path, refusing traversal. */
    public static function absolute(string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'uploads/')) {
            return null;
        }
        $abs = storage_path($rel);
        return is_file($abs) ? $abs : null;
    }

    /** Stream a stored file to the browser. */
    public static function stream(string $rel, string $downloadName, bool $inline = false, ?string $mime = null): never
    {
        $abs = self::absolute($rel);
        if (!$abs) {
            abort(404);
        }
        $mime = $mime ?: ((new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream');
        $safeName = preg_replace('/[^\w.() -]/', '_', $downloadName) ?: 'file';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Cache-Control: ' . ($inline ? 'public, max-age=86400' : 'private, no-store'));
        readfile($abs);
        exit;
    }
}
