<?php

namespace Drupal\sentinel_key\EventSubscriber;

use Drupal\sentinel_key\Event\CacheEvent;
use Drupal\sentinel_key\Service\SentinelKeyManagerInterface;
use Random\RandomException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects changes in the API encryption key and updates its hash.
 */
class EncryptionKeyChangeSubscriber implements EventSubscriberInterface {

  /**
   * The config factory service.
   *
   * @var ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   *
   * @var StateInterface
   */
  protected StateInterface $state;

  /**
   * The logger service.
   *
   * @var LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The API key manager service.
   *
   * @var SentinelKeyManagerInterface
   */
  protected SentinelKeyManagerInterface $sentinelKeyManager;

  /**
   * Constructs the subscriber.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, LoggerInterface $logger, SentinelKeyManagerInterface $sentinelKeyManager) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger;
    $this->sentinelKeyManager = $sentinelKeyManager;
  }

  /**
   * Runs once per cache clear or per session.
   * @throws RandomException
   */
  public function onFlushCache(): void
  {

    // Hash the key using SHA-256.
    $hashed_env_key = hash('sha256', $this->sentinelKeyManager->getEnvValue());

    // Load the stored encryption key hash from configuration.
    $config = $this->configFactory->getEditable('sentinel.settings');
    $stored_hashed_key = $config->get('encryption_key_hash');

    // Compare the hashed keys.
    if ($hashed_env_key !== $stored_hashed_key) {
      // Force key regeneration.
      // TODO: Regenerate keys
//      $this->apiKeyManager->forceRegenerateAllKeys();

      // Log the change.
      $this->logger->warning('API encryption key has changed. Updating the stored hash.');

      // Update the stored hash in configuration.
      $config->set('encryption_key_hash', $hashed_env_key)->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      CacheEvent::FLUSH => ['onFlushCache', 50],
    ];
  }
}
