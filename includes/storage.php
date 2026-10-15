<?php

declare(strict_types=1);

/**
 * Unified storage layer for every file the application persists
 * (product images, payment screenshots, delivery POD, expense/refund
 * receipts, supplier receipts, CMS images, company logo, ...).
 *
 * ── Backends ────────────────────────────────────────────────────────────────
 *  • 'local'     (default) writes to  uploads/<area>/<filename>  on the web
 *                server, exactly as before this layer existed.
 *  • 'supabase'  uploads to Supabase Storage and returns an ABSOLUTE public
 *                URL, which the application stores in the very same MySQL
 *                column and renders directly in <img src>/<a href>.
 *
 * Only the *binary file* moves to Supabase Storage. Every piece of business
 * metadata (which order, which invoice, who uploaded it, amounts, customers,
 * permissions …) stays in MySQL — so nothing is duplicated across databases.
 *
 * This file is included from config.php, so the functions are available
 * anywhere config.php has been loaded.
 */

require_once __DIR__ . '/env.php';

// Defensive: when this file is included first, load config for its constants.
if (!defined('DB_HOST')) {
    require_once dirname(__DIR__) . '/config.php';
}

/** Absolute path of the project root. */
function storage_root(): string
{
    return dirname(__DIR__);
}

/** Whether the Supabase integration is both enabled and fully keyed. */
function storage_supabase_configured(): bool
{
    if (!defined('SUPABASE_URL') || SUPABASE_URL === '') {
        return false;
    }
    $service = defined('SUPABASE_SERVICE_ROLE_KEY') ? SUPABASE_SERVICE_ROLE_KEY : '';
    $anon = defined('SUPABASE_ANON_KEY') ? SUPABASE_ANON_KEY : '';
    return $service !== '' || $anon !== '';
}

/** The active backend: 'supabase' only when enabled AND keyed, else 'local'. */
function storage_backend(): string
{
    if (defined('SUPABASE_STORAGE_ENABLED') && SUPABASE_STORAGE_ENABLED && storage_supabase_configured()) {
        return 'supabase';
    }
    return 'local';
}

function storage_is_remote(): bool
{
    return storage_backend() === 'supabase';
}

/** Supabase bucket name (empty string ⇒ root of the bucket for "area"). */
function storage_bucket(): string
{
    return defined('SUPABASE_STORAGE_BUCKET') ? SUPABASE_STORAGE_BUCKET : 'nared-uploads';
}

/**
 * Lazily construct the Supabase client (only when credentials exist), so no
 * request pays for parsing/starting a remote client unless it is configured.
 */
function storage_client(): ?SupabaseClient
{
    static $client = null;
    static $resolved = false;

    if (!$resolved) {
        $resolved = true;
        if (storage_supabase_configured()) {
            require_once dirname(__DIR__) . '/services/SupabaseClient.php';
            $service = defined('SUPABASE_SERVICE_ROLE_KEY') ? SUPABASE_SERVICE_ROLE_KEY : '';
            $anon = defined('SUPABASE_ANON_KEY') ? SUPABASE_ANON_KEY : '';
            $client = new SupabaseClient(
                SUPABASE_URL,
                $service !== '' ? $service : null,
                $anon !== '' ? $anon : null,
                storage_bucket()
            );
        }
    }
    return $client;
}

/** Absolute local directory for an area (e.g. "payments" => .../uploads/payments). */
function storage_local_dir(string $area): string
{
    $area = trim(str_replace('\\', '/', $area), '/');
    if ($area === '' || strpos($area, '..') !== false) {
        return storage_root() . DIRECTORY_SEPARATOR . 'uploads';
    }
    return storage_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $area);
}

/** Relative web path for a local object: "uploads/<area>/<filename>". */
function storage_local_ref(string $area, string $filename): string
{
    $area = trim(str_replace('\\', '/', $area), '/');
    return 'uploads/' . ($area !== '' ? $area . '/' : '') . $filename;
}

/** The relative web path -> filesystem path mapping used for existence checks. */
function storage_local_path(string $ref): string
{
    $ref = str_replace('\\', '/', ltrim($ref, '/'));
    if (preg_match('#^[a-z]+://#i', $ref)) {
        return '';
    }
    return storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
}

function storage_is_remote_ref($ref): bool
{
    return is_string($ref) && preg_match('#^https?://#i', $ref) === 1;
}

/** Best-effort error log that never throws (storage must not break a page). */
function storage_log(string $message): void
{
    try {
        error_log('[nared-storage] ' . $message);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Persist an uploaded/temp file.
 *
 * @param string      $tmpPath     Path to the uploaded (or temp) file
 * @param string      $area        Sub-folder / object prefix ("payments", "pod", "" for uploads/)
 * @param string      $filename    Destination file name (basename is enforced)
 * @param string|null $contentType MIME type (auto-detected when null)
 * @return string|null The stored reference (local relative path or absolute
 *                     Supabase URL), or null on failure.
 */
function storage_put(string $tmpPath, string $area, string $filename, ?string $contentType = null): ?string
{
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return null;
    }

    $filename = basename(str_replace('\\', '/', $filename));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }

    if ($contentType === null || $contentType === '') {
        $contentType = 'application/octet-stream';
        if (class_exists('finfo')) {
            $detected = (string) @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
            if ($detected !== '') {
                $contentType = $detected;
            }
        }
    }

    // ── Supabase Storage ────────────────────────────────────────────────
    if (storage_is_remote()) {
        $client = storage_client();
        if ($client !== null) {
            $objectPath = trim($area, '/') !== '' ? trim($area, '/') . '/' . $filename : $filename;
            $upload = $client->uploadFile($objectPath, $tmpPath, $contentType, true);
            if ($upload['ok'] && !empty($upload['url'])) {
                return (string) $upload['url'];
            }
            // Never lose a receipt because the remote is down: log and fall
            // back to local disk for this file.
            storage_log('Supabase upload failed, falling back to local disk: ' . (string) ($upload['error'] ?? 'unknown error'));
        }
    }

    // ── Local disk (default / fallback) ─────────────────────────────────
    $dir = storage_local_dir($area);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        storage_log('Could not create directory: ' . $dir);
        return null;
    }
    if (!is_writable($dir)) {
        storage_log('Directory not writable: ' . $dir);
        return null;
    }

    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    $saved = false;
    if (is_uploaded_file($tmpPath)) {
        $saved = move_uploaded_file($tmpPath, $dest);
    }
    if (!$saved) {
        $saved = @rename($tmpPath, $dest) || (@copy($tmpPath, $dest) && @unlink($tmpPath));
    }
    if (!$saved) {
        storage_log('Could not store file: ' . $dest);
        return null;
    }

    return storage_local_ref($area, $filename);
}

/**
 * Resolve a stored reference to something safe to render in HTML.
 *
 * Handles all three values the database may contain:
 *   • absolute Supabase URL  → returned unchanged
 *   • legacy full relative path ("uploads/payments/x.png") → returned unchanged
 *   • bare file name ("x.png") → prefixed with uploads/<area>/
 */
function storage_url($ref, string $area = ''): string
{
    if ($ref === null) {
        return '';
    }
    $ref = trim((string) $ref);
    if ($ref === '') {
        return '';
    }
    if (storage_is_remote_ref($ref)) {
        return $ref;
    }
    if (stripos($ref, 'uploads/') === 0) {
        return $ref;
    }
    if ($area !== '') {
        return storage_local_ref($area, $ref);
    }
    return $ref;
}

/**
 * Server-side existence check that understands both local and remote refs.
 * (Used where the code used to call file_exists() on a local path.)
 */
function storage_exists($ref, string $area = ''): bool
{
    if ($ref === null) {
        return false;
    }
    $ref = trim((string) $ref);
    if ($ref === '') {
        return false;
    }
    // A remote object reference is treated as present; we do not issue a
    // network call on every page render.
    if (storage_is_remote_ref($ref)) {
        return true;
    }
    $url = storage_url($ref, $area);
    if ($url === '' || storage_is_remote_ref($url)) {
        return $url !== '';
    }
    $path = storage_local_path($url);
    return $path !== '' && is_file($path);
}

/** Remove a stored reference from whichever backend holds it. */
function storage_delete($ref): bool
{
    if ($ref === null || trim((string) $ref) === '') {
        return true;
    }
    $ref = trim((string) $ref);

    if (storage_is_remote_ref($ref)) {
        $client = storage_client();
        if ($client === null) {
            return false;
        }
        $objectPath = $client->objectPathFromUrl($ref);
        if ($objectPath === null || $objectPath === '') {
            return false;
        }
        $res = $client->remove([$objectPath]);
        if (!$res['ok']) {
            storage_log('Supabase delete failed: ' . (string) $res['error']);
        }
        return (bool) $res['ok'];
    }

    $path = storage_local_path($ref);
    if ($path === '' || !is_file($path)) {
        return true; // already gone
    }
    return @unlink($path);
}
