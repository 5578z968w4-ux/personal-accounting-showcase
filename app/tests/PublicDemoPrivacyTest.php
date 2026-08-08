<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__);
$forbiddenBase64 = [
    '5ZyL5rOw',
    '5Lit5L+h',
    '5Y+w5paw',
    '6Ieq5bex',
    '6ICB5amG',
    'd2lmZQ==',
    'Y3RiYw==',
    'dGFpc2hpbg==',
];

$failures = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = $path . ': unreadable';
        continue;
    }

    foreach ($forbiddenBase64 as $encoded) {
        $needle = base64_decode($encoded, true);
        if ($needle !== false && stripos($contents, $needle) !== false) {
            $failures[] = $path . ': forbidden real-world label';
            break;
        }
    }

    if (preg_match('/entry_owner[^\n]{0,120}([\x27\x22])self\1/i', $contents) === 1) {
        $failures[] = $path . ': legacy owner value';
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

require_once $appRoot . '/src/EntryOwner.php';

if (EntryOwner::labels() !== [
    'profile_a' => '展示對象 A',
    'profile_b' => '展示對象 B',
]) {
    fwrite(STDERR, "Unexpected public Demo owner labels.\n");
    exit(1);
}

echo "PublicDemoPrivacyTest passed\n";
