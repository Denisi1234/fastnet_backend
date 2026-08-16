<?php

$dir = 'C:/Users/hp/Desktop/fastnet/web/assets/js';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$results = [];

foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'js') {
        $lines = count(file($file->getPathname()));
        $results[] = [
            'path' => str_replace('C:/Users/hp/Desktop/fastnet/web/', '', str_replace('\\', '/', $file->getPathname())),
            'lines' => $lines
        ];
    }
}

usort($results, fn($a, $b) => $b['lines'] <=> $a['lines']);

echo "========================================================================\n";
echo sprintf("%-55s | %s\n", "JS FILE", "LINE COUNT");
echo "========================================================================\n";

foreach ($results as $r) {
    $flag = $r['lines'] > 300 ? ' [EXCEEDS 300 LINES]' : ' [OK]';
    echo sprintf("%-55s | %4d lines%s\n", $r['path'], $r['lines'], $flag);
}
echo "========================================================================\n";
