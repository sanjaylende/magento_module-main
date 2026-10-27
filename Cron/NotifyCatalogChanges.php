<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Cron;

use Flipick\VideoGenerator\Model\CatalogChangeNotifier;
use Psr\Log\LoggerInterface;

/**
 * Every minute (etc/crontab.xml): tells the Flipick adapter about catalog changes collected since the last run (see
 * Model\CatalogChangeNotifier). Does nothing when nothing changed or the store is not connected.
 */
class NotifyCatalogChanges
{
    /**
     * @var CatalogChangeNotifier
     */
    private $notifier;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(CatalogChangeNotifier $notifier, LoggerInterface $logger)
    {
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $this->notifier->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: scheduled catalog notification failed', ['exception' => $e]);
        }
    }
}
