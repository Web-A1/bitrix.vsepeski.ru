<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix\Install;

use Psr\Log\LoggerInterface;

final class QueuedPlacementBindingDispatcher implements PlacementBindingDispatcher
{
    private string $queueDir;

    public function __construct(
        private readonly string $projectRoot,
        private readonly LoggerInterface $logger
    ) {
        $this->queueDir = $projectRoot . '/storage/bitrix/placement-jobs';
    }

    /**
     * @param list<string> $placements
     * @param array<string,mixed> $options
     */
    public function dispatch(
        string $domain,
        string $token,
        string $handlerUri,
        array $placements,
        array $options = []
    ): array {
        if (!$this->ensureQueueDirectory()) {
            $this->logger->warning('placement queue unavailable, bindings will not be queued');
            return ['queued' => false];
        }

        $jobId = bin2hex(random_bytes(12));
        $jobPayload = [
            'id' => $jobId,
            'domain' => $domain,
            'token' => $token,
            'handler' => $handlerUri,
            'placements' => $placements,
            'options' => $options,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $path = $this->queueDir . '/' . $jobId . '.json';
        $encoded = json_encode($jobPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($path, $encoded) === false) {
            $this->logger->error('failed to persist placement job', ['path' => $path]);
            return ['queued' => false];
        }

        $this->logger->info('placement job queued', ['job_id' => $jobId, 'domain' => $domain]);

        return ['queued' => true, 'job_id' => $jobId];
    }

    private function ensureQueueDirectory(): bool
    {
        if (is_dir($this->queueDir)) {
            return true;
        }

        return mkdir($this->queueDir, 0775, true) || is_dir($this->queueDir);
    }
}
