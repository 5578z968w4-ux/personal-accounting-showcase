<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$failures = [];

/**
 * @param list<string> $arguments
 */
function run_git(string $projectRoot, array $arguments): string
{
    $command = array_merge(['git', '-C', $projectRoot], $arguments);
    $process = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Git.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException(trim((string) $stderr) ?: 'Git command failed.');
    }

    return (string) $stdout;
}

if (!is_dir($projectRoot . '/.git')) {
    fwrite(STDERR, "Local Git repository is required.\n");
    exit(1);
}

try {
    $commitCount = (int) trim(run_git($projectRoot, ['rev-list', '--count', '--all']));
    if ($commitCount < 1) {
        throw new RuntimeException('At least one reachable commit is required.');
    }

    $commitIds = preg_split('/\r?\n/', trim(run_git($projectRoot, ['rev-list', '--all'])));
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$pathsByObject = [];
foreach ($commitIds ?: [] as $commitId) {
    if (preg_match('/^[0-9a-f]{40,64}$/', $commitId) !== 1) {
        $failures[] = 'Unable to parse reachable Git commit: ' . $commitId;
        continue;
    }

    try {
        $treeEntries = explode("\0", rtrim(
            run_git($projectRoot, ['ls-tree', '-r', '-z', '--full-tree', $commitId]),
            "\0"
        ));
    } catch (RuntimeException $exception) {
        $failures[] = $commitId . ': ' . $exception->getMessage();
        continue;
    }

    foreach ($treeEntries as $entry) {
        if ($entry === '') {
            continue;
        }
        if (preg_match('/^[0-9]{6} blob ([0-9a-f]{40,64})\t(.+)$/s', $entry, $matches) !== 1) {
            $failures[] = $commitId . ': unable to parse historical tree entry';
            continue;
        }
        $pathsByObject[$matches[1]][$matches[2]] = true;
    }
}

try {
    $allObjectLines = preg_split(
        '/\r?\n/',
        trim(run_git($projectRoot, [
            'cat-file',
            '--batch-all-objects',
            '--batch-check=%(objectname) %(objecttype) %(objectsize)',
        ]))
    );
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$scannerFiles = [
    'app/scripts/public_git_history_check.php',
    'app/scripts/public_release_check.php',
];
$forbiddenPathPatterns = [
    '#(?:^|/)\.env$#',
    '#(?:^|/)credentials\.json$#',
    '#(?:^|/)service-account\.json$#',
    '#\.(?:pem|p12|pfx|key)$#i',
    '#\.(?:sql|sqlite|sqlite3|db)$#i',
    '#\.(?:zip|tar|tgz|gz|7z)$#i',
];
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
$sensitivePatterns = [
    '/-----BEGIN (?:[A-Z ]+)?PRIVATE KEY-----/',
    '/AIza[0-9A-Za-z_-]{20,}/',
    '/github_pat_[0-9A-Za-z_]{20,}/',
    '/gh[pousr]_[0-9A-Za-z]{20,}/',
    '/sk-[A-Za-z0-9_-]{20,}/',
    '/AKIA[0-9A-Z]{16}/',
    '/xox[baprs]-[0-9A-Za-z-]{10,}/',
    '#/Users/[^/\s]+/#',
    '#/Volumes/docker#',
    '#/volume[12]/#',
    '#/www_test#',
    '/192\.168\.[0-9]{1,3}\.[0-9]{1,3}/',
    '/10\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/',
];

$blobCount = 0;
$textBlobCount = 0;
$scannedPathCount = 0;
$unreachableBlobCount = 0;

foreach ($allObjectLines ?: [] as $line) {
    if ($line === '') {
        continue;
    }

    $parts = explode(' ', $line, 3);
    if (count($parts) !== 3 || preg_match('/^[0-9a-f]{40,64}$/', $parts[0]) !== 1) {
        $failures[] = 'Unable to parse local Git object: ' . $line;
        continue;
    }

    [$objectId, $type, $sizeString] = $parts;
    if ($type !== 'blob') {
        continue;
    }
    $blobCount++;

    $paths = array_keys($pathsByObject[$objectId] ?? []);
    if ($paths === []) {
        $unreachableBlobCount++;
        $scanPaths = ['[unreachable blob]'];
    } else {
        $scanPaths = array_values(array_diff($paths, $scannerFiles));
    }
    if ($scanPaths === []) {
        continue;
    }

    foreach ($scanPaths as $path) {
        foreach ($forbiddenPathPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $failures[] = $objectId . ' ' . $path . ': forbidden historical path';
                break;
            }
        }
    }

    try {
        $size = (int) $sizeString;
        if ($size > 5 * 1024 * 1024) {
            $failures[] = $objectId . ' ' . $scanPaths[0] . ': blob exceeds 5 MiB scan limit';
            continue;
        }

        $contents = run_git($projectRoot, ['cat-file', 'blob', $objectId]);
    } catch (RuntimeException $exception) {
        $failures[] = $objectId . ': ' . $exception->getMessage();
        continue;
    }

    $scannedPathCount += count($scanPaths);
    if (str_contains(substr($contents, 0, 8192), "\0")) {
        continue;
    }
    $textBlobCount++;

    foreach ($forbiddenLabels as $encoded) {
        $needle = base64_decode($encoded, true);
        if ($needle !== false && stripos($contents, $needle) !== false) {
            $failures[] = $objectId . ' ' . $scanPaths[0] . ': forbidden real-world label';
            break;
        }
    }

    foreach ($sensitivePatterns as $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $failures[] = $objectId . ' ' . $scanPaths[0] . ': sensitive pattern detected';
            break;
        }
    }

    if (preg_match('/entry_owner[^\n]{0,120}([\x27\x22])self\1/i', $contents) === 1) {
        $failures[] = $objectId . ' ' . $scanPaths[0] . ': legacy owner value detected';
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

echo json_encode([
    'ok' => true,
    'commits_scanned' => $commitCount,
    'local_blobs_scanned' => $blobCount,
    'unreachable_blobs_scanned' => $unreachableBlobCount,
    'text_blobs_scanned' => $textBlobCount,
    'historical_paths_scanned' => $scannedPathCount,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
