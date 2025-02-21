<?php

namespace Drupal\sentinel_key\Cron;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * API Key Cleanup Cron Job.
 */
class SentinelKeyCleanupCron {

  /**
   * Database connection.
   *
   * @var Connection
   */
  protected Connection $database;

  /**
   * The logger service.
   *
   * @var LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the API key cleanup cron service.
   */
  public function __construct(Connection $database, LoggerInterface $logger) {
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * Deletes expired API keys older than 3 months.
   */
  public function cleanupExpiredKeys(): void
  {
    // TODO: update cleanup cron.
//    $threshold = strtotime('-1 months'); // Get timestamp for 3 months ago
//
//    $deletedCount = $this->database->delete('api_sentinel_keys')
//      ->condition('expires', 0, '>')
//      ->condition('expires', $threshold, '<')
//      ->execute();
//
//    if ($deletedCount > 0) {
//      $this->logger->notice('Deleted @count expired API keys older than 3 months.', ['@count' => $deletedCount]);
//    }
  }
}
