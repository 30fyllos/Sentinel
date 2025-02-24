<?php

namespace Drupal\sentinel_key\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\sentinel_key\Service\SentinelKeyManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides Sentinel key authentication.
 *
 * @AuthenticationProvider(
 *   id = "sentinel_key_auth",
 *   label = @Translation("Sentinel Key Authentication"),
 *   description = @Translation("Authenticates users via API keys with rate limiting.")
 * )
 */
class SentinelKeyAuthProvider implements AuthenticationProviderInterface {

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current path service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * The API key manager service.
   *
   * @var \Drupal\sentinel_key\Service\SentinelKeyManagerInterface
   */
  protected SentinelKeyManagerInterface $sentinelKeyManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Precompiled allowed path regex patterns.
   *
   * @var array
   */
  protected array $allowedPathRegex = [];

  /**
   * Constructs a new ApiSentinelAuthProvider.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   The current path service.
   * @param \Drupal\sentinel_key\Service\SentinelKeyManagerInterface $sentinelKeyManager
   *   The API key manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    CacheBackendInterface      $cache,
    LoggerInterface            $logger,
    ConfigFactoryInterface     $configFactory,
    CurrentPathStack           $currentPath,
    SentinelKeyManagerInterface$sentinelKeyManager,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->cache = $cache;
    $this->logger = $logger;
    $this->configFactory = $configFactory;
    $this->currentPath = $currentPath;
    $this->sentinelKeyManager = $sentinelKeyManager;
    $this->entityTypeManager = $entityTypeManager;

    // Precompile allowed paths into regex patterns.
    $config = $this->configFactory->get('sentinel_key.settings');
    $allowedPaths = $config->get('allowed_paths') ?? [];
    foreach ($allowedPaths as $pattern) {
      // Convert wildcards (*) into regex equivalent (.*) and anchor the pattern.
      $regexPattern = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
      $this->allowedPathRegex[] = $regexPattern;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Determines if this provider applies to the current request.
   */
  public function applies(Request $request): bool {
    $config = $this->configFactory->get('sentinel_key.settings');
    $customHeader = $config->get('custom_auth_header');
    // Check if an API key is provided via header or query parameter.
    return $request->headers->has($customHeader) || $request->query->has('api_key');
  }

  /**
   * {@inheritdoc}
   *
   * Authenticates the user based on an API key with rate limiting.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The authenticated user, or NULL if authentication fails.
   */
  public function authenticate(Request $request): ?AccountInterface {
    $config = $this->configFactory->get('sentinel_key.settings');
    $clientIp = $request->getClientIp();
    $currentPath = $this->currentPath->getPath();

    // Retrieve IP filtering and allowed path settings.
    $whitelist = $config->get('whitelist_ips') ?? [];
    $blacklist = $config->get('blacklist_ips') ?? [];
    $customHeader = $config->get('custom_auth_header');

    // Block if the client's IP is blacklisted.
    if (in_array($clientIp, $blacklist)) {
      $this->logger->warning('Access denied: IP {ip} is blacklisted.', ['ip' => $clientIp]);
      return NULL;
    }

    // If a whitelist is set, block any IP not in the whitelist.
    if (!empty($whitelist) && !in_array($clientIp, $whitelist)) {
      $this->logger->warning('Access denied: IP {ip} is not whitelisted.', ['ip' => $clientIp]);
      return NULL;
    }

    // Check if the current path is allowed.
    $allowed = empty($this->allowedPathRegex);
    foreach ($this->allowedPathRegex as $regex) {
      if (preg_match($regex, $currentPath)) {
        $allowed = TRUE;
        break;
      }
    }
    if (!$allowed) {
      $this->logger->warning('Access denied: Path {path} is not allowed.', ['path' => $currentPath]);
      return NULL;
    }

    // Retrieve the API key from the header or query parameter.
    $apiKey = $request->headers->get($customHeader, $request->query->get('api_key'));
    if (!$apiKey) {
      $this->logger->warning('Authentication failed: No API key provided.');
      return NULL;
    }

    // Lookup the API key entity using the entity API.
    $storage = $this->entityTypeManager->getStorage('sentinel_key');
    $api_keys = $storage->loadByProperties(['api_key' => hash('sha256', $apiKey)]);
    $apiKeyEntity = !empty($api_keys) ? reset($api_keys) : NULL;

    if (!$apiKeyEntity) {
      $this->logger->warning('Authentication failed: Invalid API key.');
      return NULL;
    }

    // Check if the API key is blocked.
//    if ($apiKeyEntity->get('blocked')->value) {
//      $this->sentinelKeyManager->logKeyUsage($apiKeyEntity->id());
//      $this->logger->warning('Blocked API key {id} attempted authentication.', ['id' => $apiKeyEntity->id()]);
//      return NULL;
//    }

    // Check for key expiration.
//    $expires = $apiKeyEntity->get('expires')->value;
//    if ($expires && time() > $expires) {
//      $this->sentinelKeyManager->logKeyUsage($apiKeyEntity->id());
//      $this->logger->warning('API key for user {uid} has expired.', ['uid' => $apiKeyEntity->get('uid')->target_id]);
//      return NULL;
//    }

    // Enforce rate limiting and failure blocking.
//    if ($this->sentinelKeyManager->blockFailedAttempt($apiKeyEntity->id()) || $this->sentinelKeyManager->checkRateLimit($apiKeyEntity->id())) {
//      return NULL;
//    }

    // CUSTOM: Check affiliation-based permissions.
    // This assumes your Sentinel Key entity has an 'affiliation' field.
//    if ($apiKeyEntity->hasField('affiliation') && !$apiKeyEntity->get('affiliation')->isEmpty()) {
//      $affiliated_entity = $apiKeyEntity->get('affiliation')->entity;
//      if ($affiliated_entity) {
//        // For example, determine the affiliation type using its entity type ID.
//        $affiliation_type = $affiliated_entity->getEntityTypeId();
//        // Load the permission mapping from configuration.
//        $permissions_config = $this->configFactory->get('sentinel_key.permissions')->get('affiliation_permissions');
//        $required_permission = $permissions_config[$affiliation_type] ?? NULL;
//        if ($required_permission && !$this->currentUser->hasPermission($required_permission)) {
//          $this->logger->warning('User does not have required permission {permission} for affiliation type {type}.', [
//            'permission' => $required_permission,
//            'type' => $affiliation_type,
//          ]);
//          return NULL;
//        }
//      }
//    }

    // Load the user entity and verify that the user is active.
    $user = User::load($apiKeyEntity->get('uid')->target_id);
    if ($user && $user->isActive()) {
      $this->sentinelKeyManager->logKeyUsage($apiKeyEntity->id(), TRUE);
      $this->logger->info('User {uid} authenticated successfully via API key.', ['uid' => $apiKeyEntity->get('uid')->target_id]);
      return $user;
    }

    $this->sentinelKeyManager->logKeyUsage($apiKeyEntity->id());
    $this->logger->warning('Authentication failed: User ID {uid} is not active.', ['uid' => $apiKeyEntity->get('uid')->target_id]);
    return NULL;
  }

}
