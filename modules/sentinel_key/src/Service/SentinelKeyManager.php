<?php

namespace Drupal\sentinel_key\Service;

use Drupal\sentinel_key\Entity\SentinelKey;
use Drupal\sentinel_key\Enum\Timeframe;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
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
   * @param int $uid
   *   The user ID.
   * @param string $message
   *   The log message.
   */
  protected function logKeyChange(int $uid, string $message): void {
    $this->logger->info($message, [
      'uid' => $uid,
      'changed_by' => $this->currentUser->id(),
    ]);
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
   * @throws Exception
   */
  public function generateApiKey(AccountInterface $account, ?int $expires = NULL): void
  {
    try {
      $apiKey = base64_encode(random_bytes(32));
    }
    catch (Exception $e) {
      $this->logger->error('Error generating API key: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }

    // Load existing API key entity for this user, if any.
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $existing = $storage->loadByProperties(['uid' => $account->id()]);
    if (!empty($existing)) {
      $apiKeyEntity = reset($existing);
    }
    else {
      $apiKeyEntity = $storage->create([]);
    }

    $apiKeyEntity->set('uid', $account->id());
    $apiKeyEntity->set('api_key', hash('sha256', $apiKey));
    $apiKeyEntity->set('data', $this->encryptValue($apiKey));
    $apiKeyEntity->set('created', time());
    if ($expires !== NULL) {
      $apiKeyEntity->set('expires', $expires);
    }
    $apiKeyEntity->save();

    $this->logKeyChange($account->id(), 'Generated a new API key.');
    $this->notificationService->notifyNewKey($account);
  }

  /**
   * {@inheritdoc}
   */
  public function forceRegenerateAllKeys(): int
  {
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $storedKeys = $storage->loadMultiple();
    $this->logger->warning('All API keys have been regenerated due to encryption key change.', [
      'changed_by' => $this->currentUser->id(),
    ]);
    $count = 0;
    foreach ($storedKeys as $apiKeyEntity) {
      $uid = $apiKeyEntity->get('uid')->target_id;
      $expires = $apiKeyEntity->get('expires')->value;
      $user = User::load($uid);
      if ($user) {
        $this->generateApiKey($user, $expires);
        $count++;
      }
    }
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function revokeApiKey(AccountInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $existing = $storage->loadByProperties(['uid' => $account->id()]);
    if (!empty($existing)) {
      foreach ($existing as $apiKeyEntity) {
        $apiKeyEntity->delete();
      }
    }
    $this->logKeyChange($account->id(), 'Revoked API key.');
  }

  /**
   * {@inheritdoc}
   */
  public function regenerateApiKey(AccountInterface $account): void {
    $expires = $this->apiKeyExpiration($account);
    // Revoke the old key.
    $this->revokeApiKey($account);
    // Generate a new key.
    $this->generateApiKey($account, $expires);
  }

  /**
   * {@inheritdoc}
   */
  public function hasApiKey(AccountInterface|string $account): int|null {
    if ($account instanceof AccountInterface) {
      $account = $account->id();
    }
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $existing = $storage->loadByProperties(['uid' => $account]);
    if (!empty($existing)) {
      $apiKeyEntity = reset($existing);
      return (int) $apiKeyEntity->id();
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function matchApiKey(AccountInterface|string $account, $keyId): ?int
  {
    if ($account instanceof AccountInterface) {
      $account = $account->id();
    }
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $apiKeyEntity = $storage->load($keyId);
    if ($apiKeyEntity && $apiKeyEntity->get('uid')->target_id == $account) {
      return (int) $apiKeyEntity->get('uid')->target_id;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKeyStatus(int $key_id): ?int {
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $apiKeyEntity = $storage->load($key_id);
    if ($apiKeyEntity) {
      return (int) $apiKeyEntity->get('blocked')->value;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   * TODO: delete later?
   */
  public function toggleApiKeyStatus(SentinelKey|int $key): bool {
    if (!$key) return FALSE;

    if (!$key instanceof SentinelKey) {
      $storage = $this->entityTypeManager->getStorage('sentinel_key');
      $key = $storage->load($key);
    }

    if ($key) {
      $key->set('blocked', !$key->isBlocked());
      $key->save();
      $message = $key->isBlocked() ? 'API key has been blocked.' : 'API key has been unblocked.';
      $this->logger->notice($message, ['key_id' => $key->id(), 'changed_by' => $this->currentUser->id()]);
      return TRUE;
    }
    return FALSE;
  }


  /**
   * {@inheritdoc}
   */
  public function apiKeyExpiration(AccountInterface|string $account): ?int {
    if ($account instanceof AccountInterface) {
      $account = $account->id();
    }
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $existing = $storage->loadByProperties(['uid' => $account]);
    if (!empty($existing)) {
      $apiKeyEntity = reset($existing);
      return $apiKeyEntity->get('expires')->value;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function logKeyUsage(int $keyId, bool $status = FALSE): void {
    $usageStorage = $this->entityTypeManager->getStorage('api_key_usage');
    $usageEntity = $usageStorage->create([
      'api_key' => $keyId,
      'used_at' => time(),
      'status' => $status ? 1 : 0,
    ]);
    $usageEntity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function checkRateLimit(int $keyId): bool {
    $this->initSettings();
    $maxRateLimit = $this->settings['max_rate_limit'] ?? 0;
    if ($maxRateLimit > 0) {
      $timeThreshold = Timeframe::fromString($this->settings['max_rate_limit_time'] ?? '1h')?->toTimestamp();
      $cache_id = "sentinel_key:usage:{$keyId}";
      if ($cache_item = $this->cache->get($cache_id)) {
        $requestCount = $cache_item->data;
      }
      else {
        $query = $this->entityTypeManager->getStorage('api_key_usage')->getQuery();
        $query->condition('api_key', $keyId);
        $query->condition('used_at', $timeThreshold, '>');
        $query->accessCheck();
        $requestCount = (int) $query->count()->execute();
        $this->cache->set($cache_id, $requestCount, time() + 60);
      }
      if ($requestCount >= $maxRateLimit) {
        $this->logger->warning('API key {id} exceeded rate limit.', ['id' => $keyId]);
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
      $cache_id = "sentinel_key:failures:{$keyId}";
      $failureCount = ($this->cache->get($cache_id)) ? $this->cache->get($cache_id)->data : 0;
      $failureCount++;
      $failureTtl = strtotime('+1 hour') - time();
      $this->cache->set($cache_id, $failureCount, time() + $failureTtl);
      if ($failureCount >= $failureLimit) {
        $storage = $this->entityTypeManager->getStorage('sentinel_key');
        $apiKeyEntity = $storage->load($keyId);
        if ($apiKeyEntity) {
          $apiKeyEntity->set('blocked', 1);
          $apiKeyEntity->save();
        }
        $this->logger->notice('API key {id} has been blocked after {failures} failed attempts.', [
          'id' => $keyId,
          'failures' => $failureCount,
        ]);
        $this->cache->delete($cache_id);
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function generateApiKeysForAllUsers(array $roles = [], ?int $expires = NULL): int
  {
    if (empty($roles)) {
      return 0;
    }
    // Using the database connection to query user data.
    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('u.uid', 1, '>') // Exclude anonymous user.
      ->condition('u.status', 1);   // Only active users.
    // If not all authenticated users, join roles and filter.
    if (!in_array('authenticated', $roles)) {
      $query->leftJoin('user__roles', 'ur', 'u.uid = ur.entity_id');
      $query->condition('ur.roles_target_id', $roles, 'IN');
    }
    $users = $query->execute()->fetchCol();
    $count = 0;
    foreach ($users as $uid) {
      if (!$this->hasApiKey($uid)) {
        $user = User::load($uid);
        if ($user && $user->isActive()) {
          $this->generateApiKey($user, $expires);
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function apiKeyUsageLast(int $keyId, string $timeCondition = '-1 hour'): mixed
  {
    $query = $this->entityTypeManager->getStorage('api_key_usage')->getQuery();
    $query->condition('api_key', $keyId);
    $query->condition('used_at', strtotime($timeCondition), '>');
    $query->accessCheck();
    return (int) $query->count()->execute();
  }
}
