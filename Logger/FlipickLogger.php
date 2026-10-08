<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Logger;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The extension's own log: var/log/flipick_videogenerator.log, rotated every day at 00:05:00 (store timezone) and at once when
 * the file passes 10 MB; the old file is zipped (flipick_videogenerator-2026-10-08.zip) and a new one started. See LogRotator.
 *
 * Warnings and errors are also passed to Magento's own logger (var/log/system.log), so they stay where support looks first.
 * Debug lines are written only in developer mode. Context values whose key looks like a secret are redacted, and an exception
 * in the context is written as class, message, file:line and the top of the trace. Logging never throws.
 */
class FlipickLogger extends AbstractLogger
{
    public const LOG_NAME = 'flipick_videogenerator';

    private const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    /**
     * @var LogRotator
     */
    private $rotator;

    /**
     * @var LoggerInterface
     */
    private $systemLogger;

    public function __construct(DirectoryList $directoryList, TimezoneInterface $timezone, LoggerInterface $systemLogger)
    {
        $this->systemLogger = $systemLogger;
        try {
            $zone = new \DateTimeZone((string)$timezone->getConfigTimezone());
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('UTC');
        }
        $this->rotator = new LogRotator($directoryList->getPath(DirectoryList::LOG), self::LOG_NAME, LogRotator::DEFAULT_MAX_BYTES, LogRotator::DEFAULT_ROTATE_AT, $zone);
    }

    public function getRotator(): LogRotator
    {
        return $this->rotator;
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     */
    public function log($level, $message, array $context = []): void
    {
        try {
            $level = strtolower((string)$level);
            $rank = self::LEVELS[$level] ?? 200;
            if ($rank < 200 && getenv('MAGE_MODE') !== 'developer' && ($_SERVER['MAGE_MODE'] ?? '') !== 'developer') {
                return;
            }
            $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . strtoupper($level) . ' ' . (string)$message;
            if ($context) {
                $encoded = json_encode($this->prepare($context), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $line .= ' ' . ($encoded === false ? '{}' : $encoded);
            }
            if (!$this->rotator->append($line . "\n")) {
                error_log('[' . self::LOG_NAME . '] ' . $line); // log folder not writable: do not lose the message
            }
            if ($rank >= 300) {
                $this->systemLogger->log($level, (string)$message, $context);
            }
        } catch (\Throwable $e) {
            // Logging must never break the request.
        }
    }

    /**
     * @param array $context
     * @return array
     */
    private function prepare(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/(secret|token|password|authorization|signature|credential|api_?key|consumer)/i', $key)) {
                $out[$key] = '[redacted]';
            } elseif ($value instanceof \Throwable) {
                $out[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'at' => basename($value->getFile()) . ':' . $value->getLine(),
                    'trace' => array_slice(explode("\n", $value->getTraceAsString()), 0, 8),
                ];
            } elseif (is_array($value)) {
                $out[$key] = $this->prepare($value);
            } elseif (is_object($value)) {
                $out[$key] = method_exists($value, '__toString') ? (string)$value : get_class($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
