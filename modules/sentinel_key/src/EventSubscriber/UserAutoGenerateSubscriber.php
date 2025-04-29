<?php

namespace Drupal\sentinel_key\EventSubscriber;

use Drupal\sentinel_key\Event\EntityCreateEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sentinel_key\Event\UserLoginEvent;
use Drupal\sentinel_key\Service\SentinelKeyManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to user entity insert events to auto-generate API keys.
 *
 * When a new user is registered, this subscriber checks the auto-generation
 * configuration. If auto-generation is enabled and the new user has one of the
 * selected roles, an API key is generated for that user.
 */
class UserAutoGenerateSubscriber implements EventSubscriberInterface {

  /**
   * The API key manager service.
   *
   * @var SentinelKeyManagerInterface
   */
  protected SentinelKeyManagerInterface $sentinelKeyManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new UserAutoGenerateSubscriber.
   *
   * @param SentinelKeyManagerInterface $sentinelKeyManager
   *    The API key manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *    The entity type manager.
   */
  public function __construct(SentinelKeyManagerInterface $sentinelKeyManager, ConfigFactoryInterface $configFactory, LoggerChannelInterface $logger, EntityTypeManagerInterface $entityTypeManager) {
    $this->sentinelKeyManager = $sentinelKeyManager;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   *
   * In Drupal 11, the constant EntityEvents::INSERT may not exist.
   * Therefore, we subscribe directly to the 'entity.insert' event.
   */
  public static function getSubscribedEvents() {
    return [
      UserLoginEvent::LOGIN => 'onUserLogin',
      EntityCreateEvent::INSERT => 'onEntityCreate',
    ];
  }

  /**
   * subscribe to the user login event Dispatched.
   */
  public function onUserLogin(UserLoginEvent $event): void
  {
    /** @var UserInterface $user */
    $user = $event->getUser();

    // Load auto-generation settings.
    $config = $this->configFactory->get('sentinel_key.settings');
    if (!$config->get('auto_generate_enabled')) {
      return;
    }

    // Add user type support. (module: user_bundle).
    $autoBundles = $config->get('auto_generate_bundles') ?: [];
    if ($this->entityTypeManager->hasDefinition('user_type') && !$autoBundles) {
      if (!in_array($user->bundle(), $autoBundles)) {
        return;
      }
    }

    $storage = $this->entityTypeManager->getStorage('sentinel_key');

    $hasKey = !empty(
    $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user->id())
      ->range(0, 1)
      ->execute()
    );

    if ($hasKey) return;

    // Get the list of roles eligible for auto-generation.
    $autoRoles = $config->get('auto_generate_roles') ?: [];
    if (empty($autoRoles)) {
      return;
    }

    // Check if the user has any role from the auto-generation list.
    $userRoles = $user->getRoles();
    $matchedRoles = array_intersect($userRoles, $autoRoles);
    if (empty($matchedRoles)) {
      return;
    }

    // Determine the expiration timestamp if provided.
    $duration = $config->get('auto_generate_duration');
    $unit = $config->get('auto_generate_duration_unit');
    $expires = $duration ? strtotime( "+ {$duration} {$unit}") : NULL;

    // Generate an API key for the new user.
    try {
      $sentinelKey = $storage->create([
        'uid' => $user->id(),
        'expires' => $expires,
        'status' => 1,
      ]);
      $sentinelKey->save();
      $this->logger->info('Auto-generated API key for user ID @uid.', ['@uid' => $user->id()]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to auto-generate API key for user ID @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
    }

  }

  /**
   * Responds to a new user entity being inserted.
   *
   * @param EntityCreateEvent $event
   *   The event triggered on entity insertion.
   */
  public function onEntityCreate(EntityCreateEvent $event): void
  {
    $user = $event->getEntity();
    // Only act on user entities.
    if ($user->getEntityTypeId() !== 'user') {
      return;
    }

    // Load auto-generation settings.
    $config = $this->configFactory->get('sentinel_key.settings');
    if (!$config->get('auto_generate_enabled')) {
      return;
    }

    // Add user type support. (module: user_bundle).
    $autoBundles = $config->get('auto_generate_bundles') ?: [];
    if ($this->entityTypeManager->hasDefinition('user_type') && !$autoBundles) {
      if (!in_array($user->bundle(), $autoBundles)) {
        return;
      }
    }

    $storage = $this->entityTypeManager->getStorage('sentinel_key');

    // Get the list of roles eligible for auto-generation.
    $autoRoles = $config->get('auto_generate_roles') ?: [];
    if (empty($autoRoles)) {
      return;
    }

    // Check if the new user has any role from the auto-generation list.
    $user_roles = $user->getRoles();
    $matched_roles = array_intersect($user_roles, $autoRoles);
    if (empty($matched_roles)) {
      return;
    }

    // Determine the expiration timestamp if provided.
    $duration = $config->get('auto_generate_duration');
    $unit = $config->get('auto_generate_duration_unit');
    $expires = $duration ? strtotime( "+ {$duration} {$unit}") : NULL;

    // Generate an API key for the new user.
    try {
      $sentinelKey = $storage->create([
        'uid' => $user->id(),
        'expires' => $expires,
        'status' => 1,
      ]);
      $sentinelKey->save();
      $this->logger->info('Auto-generated API key for user ID @uid.', ['@uid' => $user->id()]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to auto-generate API key for user ID @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
