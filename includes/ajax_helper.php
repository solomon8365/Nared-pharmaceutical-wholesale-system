<?php
/**
 * AJAX response utilities.
 *
 * When a page includes this file and its POST handler ends with:
 *   if (is_ajax()) { ajax_respond($messages); }
 * the request is short-circuited before any HTML is rendered.
 *
 * However the *preferred* approach is zero-per-page-change: index.php uses
 * output buffering to capture the rendered HTML and extract messages
 * automatically (see the AJAX_MID section in index.php).
 */

if (!function_exists('is_ajax')) {
    /** Detect an AJAX request made by the frontend interceptor. */
    function is_ajax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }
}

if (!function_exists('ajax_respond')) {
    /**
     * Output a JSON response and terminate.
     *
     * @param array  $messages  Array of ['type' => 'success'|'error', 'text' => '...']
     * @param array  $extra     Optional extra payload (DOM-update hints, data, etc.)
     */
    function ajax_respond(array $messages = [], array $extra = []): never
    {
        $hasError = false;
        $texts    = [];
        foreach ($messages as $m) {
            $texts[] = ['type' => $m['type'] ?? 'info', 'text' => $m['text'] ?? ''];
            if (($m['type'] ?? '') === 'error') $hasError = true;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success'  => !$hasError && !empty($texts),
            'messages' => $texts,
        ], $extra));
        exit;
    }
}
