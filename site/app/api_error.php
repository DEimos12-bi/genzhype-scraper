<?php
/**
 * GenZHype | error text for API callers (2026-09-24 cleanup review).
 *
 * The endpoints answered GitHub runners with the raw exception text, and the
 * runners' logs are PUBLIC (the repo is public), so SQL fragments and server
 * paths could surface there. The full text now goes to app/api_errors.log and
 * the caller gets a short reference to find it.
 */
function api_error_ref(Throwable $e, string $where): string {
    $ref = bin2hex(random_bytes(4));
    @file_put_contents(__DIR__ . '/api_errors.log',
        date('c') . " [{$ref}] {$where}: " . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND);
    return "internal error (ref {$ref})";
}
