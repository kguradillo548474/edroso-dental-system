<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_get_token(): string
{
    return (string) ($_SESSION['csrf_token'] ?? '');
}

function validate_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function csrf_request_token(?array $jsonBody = null): string
{
    $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($headerToken !== '') {
        return $headerToken;
    }
    $postToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postToken !== '') {
        return $postToken;
    }
    if (is_array($jsonBody) && isset($jsonBody['csrf_token'])) {
        return (string) $jsonBody['csrf_token'];
    }
    return '';
}

function csrf_require_valid(?array $jsonBody = null): void
{
    $submittedToken = csrf_request_token($jsonBody);
    if (!validate_csrf($submittedToken)) {
        if (function_exists('respond')) {
            respond(['error' => 'Invalid or missing CSRF token.'], 403);
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}
