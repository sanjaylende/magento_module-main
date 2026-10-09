<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Cron;

use Flipick\VideoGenerator\Logger\FlipickLogger;

/**
 * Daily at 00:05 (etc/crontab.xml): rotates the extension's log if its day has ended, even on a store with no traffic.
 * The log also rotates by itself on the first line written after 00:05 and whenever it passes 10 MB.
 */
class RotateLogs
{
    /**
     * @var FlipickLogger
     */
    private $logger;

    public function __construct(FlipickLogger $logger)
    {
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $archive = $this->logger->getRotator()->rotateIfDue();
            if ($archive !== null) {
                $this->logger->info('Flipick: log rotated', ['archive' => basename($archive)]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: scheduled log rotation failed', ['exception' => $e]);
        }
    }
}
