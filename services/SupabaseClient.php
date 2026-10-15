<?php

declare(strict_types=1);

/**
 * Minimal server-side Supabase REST client (no Composer dependency).
 *
 * Talks to the Supabase HTTPS API using cURL. Because this app runs on a
 * PHP/MariaDB stack (no pdo_pgsql driver installed), Supabase is reached
 * ONLY through its REST/Storage API — never as a Postgres database.
 *
 * Security:
 *   - The service-role key is a server-side secret and is never rendered.
 *   - Only the Storage API is used; Auth/Postgres are intentionally unused
 *     (see docs/SUPABASE.md for the rationale).
 *
 * Every method returns an array with at least: ok (bool), status (int),
 * error (?string). On success 'json' holds the decoded payload.
 */
class SupabaseClient
{
    private string $url;
    private ?string $serviceKey;
    private ?string $anonKey;
    private string $bucket;
    private int $timeout;

    public function __construct(
        string $url,
        ?string $serviceKey = null,
        ?string $anonKey = null,
        string $bucket = 'nared-uploads',
        int $timeout = 20
    ) {
        $this->url = rtrim(trim($url), '/');
        $this->serviceKey = ($serviceKey !== null && $serviceKey !== '') ? $serviceKey : null;
        $this->anonKey = ($anonKey !== null && $anonKey !== '') ? $anonKey : null;
        $this->bucket = $bucket !== '' ? $bucket : 'nared-uploads';
        $this->timeout = $timeout;
    }

    /** True when we have enough configuration to attempt a remote call. */
    public function isConfigured(): bool
    {
        return $this->url !== '' && ($this->serviceKey !== null || $this->anonKey !== null);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    /** Key used for Storage calls: service-role when available, else anon. */
    private function authKey(): string
    {
        return $this->serviceKey ?? $this->anonKey ?? '';
    }

    /* ─────────────────────────── low-level HTTP ─────────────────────────── */

    /**
     * Perform a raw HTTP request against the Supabase API.
     *
     * @param string      $method  HTTP method (GET/POST/PUT/DELETE/HEAD)
     * @param string      $path    Path after the project root (e.g. /storage/v1/version)
     * @param mixed       $body    Array to JSON-encode, raw string, or null
     * @param array       $headers Extra headers (assoc => value)
     * @param array       $query   Extra query parameters
     * @param array       $opts    curl option overrides
     * @return array{ok:bool,status:int,error:?string,json:mixed,body:string}
     */
    public function request(string $method, string $path, $body = null, array $headers = [], array $query = [], array $opts = []): array
    {
        if ($this->url === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'Supabase URL is not configured.', 'json' => null, 'body' => ''];
        }

        $endpoint = $this->url . $path;
        if ($query) {
            $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $h = [];
        $apiKey = $this->authKey();
        if ($apiKey !== '') {
            $h[] = 'apikey: ' . $apiKey;
            $h[] = 'Authorization: Bearer ' . $apiKey;
        }

        $payload = null;
        if ($body !== null) {
            if (is_array($body) || is_object($body)) {
                $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($payload === false) {
                    return ['ok' => false, 'status' => 0, 'error' => 'Failed to encode request body.', 'json' => null, 'body' => ''];
                }
                $h[] = 'Content-Type: application/json';
            } else {
                $payload = (string) $body;
            }
        }

        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        foreach ($opts as $opt => $val) {
            curl_setopt($ch, $opt, $val);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            return ['ok' => false, 'status' => $status, 'error' => 'cURL error ' . $errno . ': ' . $err, 'json' => null, 'body' => ''];
        }

        $json = json_decode((string) $response, true);
        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'error' => ($status >= 200 && $status < 300) ? null : $this->describeError($status, (string) $response),
            'json'  => is_array($json) ? $json : null,
            'body'  => (string) $response,
        ];
    }

    private function describeError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = null;
        if (is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['error'] ?? $decoded['error_description'] ?? null;
            if (is_array($message)) {
                $message = implode(' ', array_map('strval', $message));
            }
            if ($message === null && isset($decoded['Error'])) {
                $message = (string) $decoded['Error'];
            }
        }
        if (!is_string($message) || trim($message) === '') {
            $snippet = trim(preg_replace('/\s+/', ' ', substr($body, 0, 300)) ?? '');
            $message = $snippet !== '' ? $snippet : ('HTTP ' . $status);
        }
        return 'HTTP ' . $status . ': ' . $message;
    }

    /* ───────────────────────────── health ───────────────────────────── */

    /**
     * Cheap reachability + authorization probe.
     *
     * @return array{ok:bool,reachable:bool,storage_authorized:bool,error:?string,status:int}
     */
    public function health(): array
    {
        if ($this->url === '') {
            return ['ok' => false, 'reachable' => false, 'storage_authorized' => false, 'error' => 'Supabase URL is not configured.', 'status' => 0];
        }

        // 1) Reachability (no auth required).
        $probe = $this->request('GET', '/storage/v1/version');
        if (!$probe['ok'] && $probe['status'] === 0) {
            return ['ok' => false, 'reachable' => false, 'storage_authorized' => false, 'error' => $probe['error'], 'status' => 0];
        }
        $reachable = $probe['status'] !== 0;

        // 2) Authorization probe: read the target bucket.
        $bucket = $this->bucketExists();
        $authorized = (bool) ($bucket['json'] !== null);

        return [
            'ok' => $reachable && $authorized,
            'reachable' => $reachable,
            'storage_authorized' => $authorized,
            'error' => $authorized ? null : ($bucket['error'] ?? $probe['error'] ?? 'Unable to read storage bucket.'),
            'status' => $probe['status'],
        ];
    }

    /* ───────────────────────────── storage ───────────────────────────── */

    /** GET /storage/v1/bucket/{bucket} — returns the bucket JSON on success. */
    public function bucketExists(): array
    {
        return $this->request('GET', '/storage/v1/bucket/' . rawurlencode($this->bucket));
    }

    /**
     * Create the bucket if it does not exist (idempotent).
     *
     * @return array{created:bool,exists:bool,error:?string}
     */
    public function ensureBucket(bool $public = false): array
    {
        $exists = $this->bucketExists();
        if ($exists['ok'] || $exists['status'] === 200) {
            return ['created' => false, 'exists' => true, 'error' => null];
        }
        if ($exists['status'] === 404) {
            $created = $this->request('POST', '/storage/v1/bucket', [
                'id' => $this->bucket,
                'name' => $this->bucket,
                'public' => $public,
            ]);
            if ($created['ok']) {
                return ['created' => true, 'exists' => true, 'error' => null];
            }
            return ['created' => false, 'exists' => false, 'error' => $created['error'] ?? 'Could not create bucket.'];
        }
        return ['created' => false, 'exists' => false, 'error' => $exists['error'] ?? 'Could not verify bucket.'];
    }

    /**
     * Upload a local file into the bucket.
     *
     * @param string $objectPath  Object key, e.g. "payments/pay_1.png"
     * @param string $localPath   Path to an existing local file
     * @param string $contentType MIME type
     * @param bool   $upsert      Overwrite an existing object
     * @return array{ok:bool,error:?string,status:int,url:?string}
     */
    public function uploadFile(string $objectPath, string $localPath, string $contentType = 'application/octet-stream', bool $upsert = true): array
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            return ['ok' => false, 'error' => 'Local file not readable: ' . $localPath, 'status' => 0, 'url' => null];
        }

        $content = file_get_contents($localPath);
        if ($content === false) {
            return ['ok' => false, 'error' => 'Could not read local file: ' . $localPath, 'status' => 0, 'url' => null];
        }

        return $this->uploadContent($objectPath, $content, $contentType, $upsert);
    }

    /**
     * Upload raw content into the bucket.
     *
     * @param string $objectPath  Object key
     * @param string $content     Raw bytes
     * @param string $contentType MIME type
     * @param bool   $upsert      Overwrite an existing object
     * @return array{ok:bool,error:?string,status:int,url:?string}
     */
    public function uploadContent(string $objectPath, string $content, string $contentType = 'application/octet-stream', bool $upsert = true): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Supabase Storage is not configured.', 'status' => 0, 'url' => null];
        }

        $objectPath = ltrim($objectPath, '/');
        $res = $this->request(
            'POST',
            '/storage/v1/object/' . rawurlencode($this->bucket) . '/' . $objectPath,
            $content,
            ['Content-Type' => $contentType, 'x-upsert' => $upsert ? 'true' : 'false']
        );

        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Upload failed.', 'status' => $res['status'], 'url' => null];
        }

        return ['ok' => true, 'error' => null, 'status' => $res['status'], 'url' => $this->publicUrl($objectPath)];
    }

    /**
     * Delete one or more objects from the bucket.
     *
     * @param string[] $objectPaths Object keys
     * @return array{ok:bool,error:?string,status:int}
     */
    public function remove(array $objectPaths): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Supabase Storage is not configured.', 'status' => 0];
        }
        $paths = array_values(array_filter(array_map(static function ($p) {
            return ltrim((string) $p, '/');
        }, $objectPaths), static function ($p) {
            return $p !== '';
        }));
        if (!$paths) {
            return ['ok' => true, 'error' => null, 'status' => 200];
        }

        $res = $this->request('POST', '/storage/v1/object/' . rawurlencode($this->bucket), ['prefixes' => $paths]);
        return [
            'ok' => $res['ok'],
            'error' => $res['ok'] ? null : ($res['error'] ?? 'Delete failed.'),
            'status' => $res['status'],
        ];
    }

    /** Public (unauthenticated) URL for an object in a public bucket. */
    public function publicUrl(string $objectPath): string
    {
        return $this->url . '/storage/v1/object/public/' . rawurlencode($this->bucket) . '/' . ltrim($objectPath, '/');
    }

    /**
     * List objects under a prefix.
     *
     * @return array{ok:bool,error:?string,status:int,json:?array}
     */
    public function list(string $prefix = '', int $limit = 100, int $offset = 0): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Supabase Storage is not configured.', 'status' => 0, 'json' => null];
        }
        $res = $this->request('POST', '/storage/v1/object/list/' . rawurlencode($this->bucket), [
            'prefix' => $prefix,
            'limit' => $limit,
            'offset' => $offset,
            'sortBy' => ['column' => 'created_at', 'order' => 'desc'],
        ]);
        return ['ok' => $res['ok'], 'error' => $res['error'], 'status' => $res['status'], 'json' => $res['json']];
    }

    /**
     * Derive the object key from a previously stored public URL (or null when
     * the value is not a Supabase Storage URL).
     */
    public function objectPathFromUrl(string $value): ?string
    {
        if ($value === '' || stripos($value, 'http') !== 0) {
            return null;
        }
        $marker = '/storage/v1/object/public/' . rawurlencode($this->bucket) . '/';
        $pos = strpos($value, $marker);
        if ($pos === false) {
            // Fall back: match any public bucket segment.
            if (!preg_match('#/storage/v1/object/public/[^/]+/#', $value, $m, PREG_OFFSET_CAPTURE)) {
                return null;
            }
            $start = $m[0][1] + strlen($m[0][0]);
            return urldecode(substr($value, $start));
        }
        return urldecode(substr($value, $pos + strlen($marker)));
    }
}
