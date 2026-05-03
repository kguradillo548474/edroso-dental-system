<?php
/**
 * Shared portal contact validation (registration, booking, etc.).
 */

/**
 * Normalize phone input the same way as portal registration (spaces and hyphens stripped).
 */
function normalize_portal_phone_for_validation(string $phone): string
{
    return preg_replace('/[\s\-]/', '', trim($phone));
}

/**
 * @return string|null null if valid, otherwise a short error message for the client
 */
function validate_portal_email(string $email): ?string
{
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

/**
 * @return string|null null if valid, otherwise a short error message for the client
 */
function validate_portal_phone(string $phoneRaw): ?string
{
    $phone = normalize_portal_phone_for_validation($phoneRaw);
    if (!preg_match('/^\+639\d{9}$/', $phone)) {
        return 'Mobile number must be +639 followed by 9 digits (Philippines).';
    }
    return null;
}
