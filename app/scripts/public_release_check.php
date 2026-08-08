<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$allowlistPath = $projectRoot . '/PUBLIC_ALLOWLIST.txt';
$failures = [];

if (!is_file($allowlistPath)) {
    fwrite(STDERR, "PUBLIC_ALLOWLIST.txt is missing.\n");
    exit(1);
}

$allowlist = file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($allowlist === false) {
    fwrite(STDERR, "PUBLIC_ALLOWLIST.txt is unreadable.\n");
    exit(1);
}
sort($allowlist, SORT_STRING);

$actualFiles = [];
$textFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $relative = ltrim(substr($path, strlen($projectRoot)), DIRECTORY_SEPARATOR);

    if ($relative === '.git' || str_starts_with($relative, '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    if ($file->getFilename() === '.DS_Store') {
        continue;
    }
    if ($file->isLink()) {
        $failures[] = $relative . ': symbolic link is not allowed';
        continue;
    }
    if (!$file->isFile()) {
        continue;
    }

    $actualFiles[] = $relative;
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = $relative . ': unreadable';
        continue;
    }
    if (!str_contains(substr($contents, 0, 8192), "\0")) {
        $textFiles[$relative] = $contents;
    }
}
sort($actualFiles, SORT_STRING);

if ($allowlist !== array_values(array_unique($allowlist))) {
    $failures[] = 'PUBLIC_ALLOWLIST.txt contains duplicate entries';
}
if ($allowlist !== $actualFiles) {
    foreach (array_diff($allowlist, $actualFiles) as $missing) {
        $failures[] = $missing . ': allowlisted file is missing';
    }
    foreach (array_diff($actualFiles, $allowlist) as $unexpected) {
        $failures[] = $unexpected . ': file is not allowlisted';
    }
}

foreach (['.env', 'credentials.json', 'service-account.json'] as $forbiddenFile) {
    if (in_array($forbiddenFile, $actualFiles, true)) {
        $failures[] = $forbiddenFile . ': private configuration file is not allowed';
    }
}

$forbiddenLabels = [
    '5ZyL5rOw',
    '5Lit5L+h',
    '5Y+w5paw',
    '6Ieq5bex',
    '6ICB5amG',
    'd2lmZQ==',
    'Y3RiYw==',
    'dGFpc2hpbg==',
];
$credentialPatterns = [
    '/-----BEGIN (?:[A-Z ]+)?PRIVATE KEY-----/',
    '/AIza[0-9A-Za-z_-]{20,}/',
    '/github_pat_[0-9A-Za-z_]{20,}/',
    '/gh[pousr]_[0-9A-Za-z]{20,}/',
    '/sk-[A-Za-z0-9_-]{20,}/',
    '/AKIA[0-9A-Z]{16}/',
    '/xox[baprs]-[0-9A-Za-z-]{10,}/',
];
$privateContextPatterns = [
    '#/Users/[^/\s]+/#',
    '#/Volumes/docker#',
    '#/volume[12]/#',
    '#/www_test#',
    '/192\.168\.[0-9]{1,3}\.[0-9]{1,3}/',
    '/10\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/',
];

foreach ($textFiles as $relative => $contents) {
    foreach ($forbiddenLabels as $encoded) {
        $needle = base64_decode($encoded, true);
        if ($needle !== false && stripos($contents, $needle) !== false) {
            $failures[] = $relative . ': forbidden real-world label';
            break;
        }
    }
    if (!in_array($relative, [
        'app/scripts/public_git_history_check.php',
        'app/scripts/public_release_check.php',
    ], true)) {
        foreach (array_merge($credentialPatterns, $privateContextPatterns) as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $failures[] = $relative . ': sensitive pattern detected';
                break;
            }
        }
        if (preg_match('/entry_owner[^\n]{0,120}([\x27\x22])self\1/i', $contents) === 1) {
            $failures[] = $relative . ': legacy owner value detected';
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

echo json_encode([
    'ok' => true,
    'allowlisted_files' => count($actualFiles),
    'text_files_scanned' => count($textFiles),
    'symlinks' => 0,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
