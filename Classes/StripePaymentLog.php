<?php

namespace StripePayment\Classes;

/**
 * Writes Stripe-specific logs to a dedicated file, with size-based rotation.
 *
 * Does not touch the global Tlog singleton: each call writes directly to its
 * own file via fopen/flock/fwrite, leaving other Thelia loggers untouched.
 */
class StripePaymentLog
{
    public const EMERGENCY = 'EMERGENCY';
    public const ALERT     = 'ALERT';
    public const CRITICAL  = 'CRITICAL';
    public const ERROR     = 'ERROR';
    public const WARNING   = 'WARNING';
    public const NOTICE    = 'NOTICE';
    public const INFO      = 'INFO';
    public const DEBUG     = 'DEBUG';

    private const FILE_NAME = 'log-stripe.txt';
    private const MAX_FILE_SIZE_BYTES = 2 * 1024 * 1024;
    private const MAX_BACKUP_COUNT = 10;

    public function logText(string $message, string $severity = self::INFO, string $category = 'stripe'): void
    {
        $filePath = THELIA_LOG_DIR . self::FILE_NAME;

        $this->rotateIfNeeded($filePath);

        $line = sprintf(
            '[%s] %s.%s: %s%s',
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $category,
            $severity,
            $message,
            PHP_EOL
        );

        @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
    }

    private function rotateIfNeeded(string $filePath): void
    {
        if (!is_file($filePath) || filesize($filePath) <= self::MAX_FILE_SIZE_BYTES) {
            return;
        }

        $timestamp = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
        @rename($filePath, $filePath.'.'.$timestamp);

        $this->cleanupBackups($filePath);
    }

    private function cleanupBackups(string $filePath): void
    {
        $files = glob($filePath.'.*') ?: [];

        if (count($files) <= self::MAX_BACKUP_COUNT) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        $excess = array_slice($files, 0, count($files) - self::MAX_BACKUP_COUNT);
        foreach ($excess as $file) {
            @unlink($file);
        }
    }
}
