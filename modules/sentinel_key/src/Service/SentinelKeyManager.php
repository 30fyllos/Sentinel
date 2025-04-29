<?php

namespace Drupal\sentinel_key\Service;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\sentinel_key\Entity\SentinelKey;
use Drupal\sentinel_key\Enum\Timeframe;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\sentinel_key\SentinelKeyInterface;
use Drupal\user\Entity\User;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Class SentinelApiKeyManager.
 *
 * Provides methods to manage API keys including encryption, generation,
 * revocation, rate limiting, and usage logging.
 */
class SentinelKeyManager implements SentinelKeyManagerInterface {

  /**
   * The database connection.
   *
   * @var EntityTypeManager
   */
  protected EntityTypeManager $entityTypeManager;


  /**
   * The database connection.
   *
   * @var Connection
   */
  protected Connection $database;

  /**
   * The configuration factory.
   *
   * @var ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The cache backend.
   *
   * @var CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The logger service.
   *
   * @var LoggerInterface
   */
  public LoggerInterface $logger;

  /**
   * The current user service.
   *
   * @var AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The temp store factory.
   *
   * @var PrivateTempStoreFactory
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * The notification service.
   *
   * @var SentinelKeyNotificationServiceInterface
   */
  protected SentinelKeyNotificationServiceInterface $notificationService;

  /**
   * Cached configuration settings.
   *
   * @var array
   */
  protected array $settings = [];

  /**
   * Constructs a new ApiKeyManager.
   *
   * @param EntityTypeManager $entityTypeManager
   *   The entity type manager.
   * @param Connection $database
   *   The database connection.
   * @param ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param LoggerInterface $logger
   *   The logger service.
   * @param CacheBackendInterface $cache
   *   The cache backend.
   * @param AccountProxyInterface $currentUser
   *   The current user.
   * @param PrivateTempStoreFactory $tempStoreFactory
   *   The temp store factory.
   * @param SentinelKeyNotificationServiceInterface $notificationService
   *    The service for sending API key notifications
   */
  public function __construct(EntityTypeManager $entityTypeManager, Connection $database, ConfigFactoryInterface $configFactory, LoggerInterface $logger, CacheBackendInterface $cache, AccountProxyInterface $currentUser, PrivateTempStoreFactory $tempStoreFactory, SentinelKeyNotificationServiceInterface $notificationService) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
    $this->cache = $cache;
    $this->currentUser = $currentUser;
    $this->tempStoreFactory = $tempStoreFactory;
    $this->notificationService = $notificationService;
  }

  /**
   * Initializes the configuration settings if not already set.
   */
  protected function initSettings(): void {
    if (empty($this->settings)) {
      $this->settings = $this->configFactory->get('sentinel_key.settings')->getRawData();
    }
  }

  /**
   * Logs API key changes.
   *
   * @param string $message
   *   The log message.
   * @param string|null $type
   *   The log type.
   *
   * @return void
   */
  protected function logKey(string $message, string $type = null): void {
    switch ($type) {
    case 'warning':
      $this->logger->warning($message, [
      'changed_by' => $this->currentUser->id(),
      ]);
      break;
    case 'notice':
      $this->logger->notice($message, [
        'changed_by' => $this->currentUser->id(),
      ]);
      break;
    default:
      $this->logger->info($message, [
        'changed_by' => $this->currentUser->id(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEnvValue(): string
  {
    $env_path = dirname(DRUPAL_ROOT) . '/.env';

    if (file_exists($env_path)) {
      $env_values = parse_ini_file($env_path);
      return $env_values['SENTINEL_ENCRYPTION_KEY'] ?? '';
    }

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function encryptValue(string $value): string {
    $this->initSettings();
    $encryptionKey = $this->getEnvValue() ?? $this->settings['sentinel_key'];

    if (!$encryptionKey) {
      throw new Exception('Encryption key is invalid.');
    }
    // Use a random IV for better security.
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $encryptionKey, 0, $iv);
    // Prepend IV to the encrypted value.
    return base64_encode($iv . $encrypted);
  }

  /**
   * {@inheritdoc}
   */
  public function decryptValue(string $encryptedValue): false|string
  {
    $this->initSettings();
    $encryptionKey = $this->getEnvValue() ?? $this->settings['sentinel_key'];
    if (!$encryptionKey) {
      return 'Error: Invalid encryption key.';
    }
    $data = base64_decode($encryptedValue);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $encryptionKey, 0, $iv);
  }

  /**
   * {@inheritdoc}
   */
  public function forceRegenerateAllKeys(): int
  {
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $storedKeys = $storage->loadMultiple();

    $count = 0;
    /** @var SentinelKeyInterface $apiKeyEntity */
    foreach ($storedKeys as $apiKeyEntity) {
      $apiKeyEntity->genApiKey();
      $apiKeyEntity->save();
      $count++;
    }
    if($count) {
      $this->logKey("Total {$count} API keys have been regenerated due to encryption key change.", "warning");
    }
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function hasApiKey(AccountInterface|string $account): bool {
    if ($account instanceof AccountInterface) {
      $account = $account->id();
    }
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $existing = $storage->loadByProperties(['uid' => $account]);

    return !empty($existing);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRateLimit(int $keyId): bool {
    $this->initSettings();
    $maxRateLimit = $this->settings['max_rate_limit'] ?? 0;
    if ($maxRateLimit > 0) {
      $timestamp = \Drupal::time()->getCurrentTime();
      $cid = "sentinel_key:usage:$keyId";
      $cache = $this->cache->get($cid);
      $usages = $cache ? $cache->data : [];

      $usages[] = $timestamp;
      $usages = array_filter($usages, fn($ts) => $timestamp - $ts <= 3600);

      $this->cache->set($cid, $usages, $timestamp + 3600);

      $windowStart = Timeframe::fromString($this->settings['max_rate_limit_time'] ?? '1h')?->toTimestamp();
      $recentUsage = array_filter($usages, fn($ts) => $ts >= $windowStart);

      if (count($recentUsage) >= $maxRateLimit) {
        $this->logKey("API key {$keyId} exceeded rate limit.", 'warning');
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function blockFailedAttempt(int $keyId): bool {
    $this->initSettings();
    $failureLimit = $this->settings['failure_limit'] ?? 0;
    if ($failureLimit > 0) {
      $timestamp = \Drupal::time()->getCurrentTime();
      $cid = "sentinel_key:failures:$keyId";
      $cache = $this->cache->get($cid);
      $failures = $cache ? $cache->data : [];

      $failures[] = $timestamp;

      // Keep only the last hour of failures
      $failures = array_filter($failures, fn($ts) => $timestamp - $ts <= 3600);

      $this->cache->set($cid, $failures, $timestamp + 3600);

      if (count($failures) >= $failureLimit) {
        $storage = $this->entityTypeManager->getStorage('sentinel_key');
        $apiKeyEntity = $storage->load($keyId);
        if ($apiKeyEntity) {
          $apiKeyEntity->set('blocked', 0);
          $apiKeyEntity->save();
        }
        $countFailures = count($failures);
        $this->logKey("API key {$keyId} has been blocked after {$countFailures} failed attempts.", 'notice');
        $this->cache->delete($cid);
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function resetFailureWindow(int $keyId): void {
    $cid = "sentinel_key:failures:$keyId";
    $this->cache->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
//  public function generateApiKeysForAllUsers(array $roles = [], ?int $expires = NULL): int
//  {
//    if (empty($roles)) {
//      return 0;
//    }
//    // Using the database connection to query user data.
//    $query = $this->database->select('users_field_data', 'u')
//      ->fields('u', ['uid'])
//      ->condition('u.uid', 1, '>') // Exclude anonymous user.
//      ->condition('u.status', 1);   // Only active users.
//    // If not all authenticated users, join roles and filter.
//    if (!in_array('authenticated', $roles)) {
//      $query->leftJoin('user__roles', 'ur', 'u.uid = ur.entity_id');
//      $query->condition('ur.roles_target_id', $roles, 'IN');
//    }
//    $users = $query->execute()->fetchCol();
//    $count = 0;
//    foreach ($users as $uid) {
//      if (!$this->hasApiKey($uid)) {
//        $user = User::load($uid);
//        if ($user && $user->isActive()) {
//          $this->generateApiKey($user, $expires);
//          $count++;
//        }
//      }
//    }
//    return $count;
//  }
}
