<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Shared by PHP workers and cron on a host using the same data directory. */
function trendyol_rate_slot(string $scope, float $interval, ?float $pauseUntil = null): void
{
    $directory = dirname(DB_PATH) . '/trendyol-rate';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Trendyol kota dizini açılamadı.');
    }
    $file = fopen($directory . '/' . hash('sha256', $scope), 'c+');
    if ($file === false) throw new RuntimeException('Trendyol kota kaydı açılamadı.');
    try {
        if (!flock($file, LOCK_EX)) throw new RuntimeException('Trendyol kota kilidi alınamadı.');
        $next = (float) stream_get_contents($file);
        $now = microtime(true);
        if ($pauseUntil === null && $next - $now > 5.1) {
            throw new RuntimeException('Trendyol kotası beklemede. Daha sonra tekrar deneyin.');
        }
        if ($pauseUntil === null && $next > $now) {
            usleep((int) ceil(($next - $now) * 1000000));
        }
        $value = sprintf('%.6F', $pauseUntil === null ? microtime(true) + $interval : max($next, $pauseUntil));
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $value) !== strlen($value) || !fflush($file)) {
            throw new RuntimeException('Trendyol kota kaydı yazılamadı.');
        }
    } finally {
        fclose($file);
    }
}
