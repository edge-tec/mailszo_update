<?php
/**
 * Mailpro Dedicated Email Open Tracking Pixel Endpoint
 *
 * Handles:
 *   GET /track/open/{token}.png
 *   GET /track.php?token={token}
 *
 * Fast, unauthenticated, zero-cache 1x1 transparent PNG response
 * with asynchronous background intelligence extraction.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/tracking_engine.php';

// Output 1x1 transparent PNG immediately
outputTrackingTransparentPng();

// Extract tracking token from query string or URL path
$token = trim($_GET['token'] ?? ($_GET['t'] ?? ''));
if ($token === '') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/track/open/([a-zA-Z0-9_-]+)(\.png)?#i', $uri, $m)) {
        $token = trim($m[1]);
    }
}

// Security: Ignore invalid / empty tokens
if ($token === '' || strlen($token) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
    exit;
}

// Asynchronously record open event in database with location & device intelligence
try {
    recordTrackingOpenEvent($token, [
        'ip'              => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referer'         => $_SERVER['HTTP_REFERER'] ?? null,
        'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
    ]);
} catch (\Throwable $e) {
    // Fail silently to never break pixel response
}

exit;
