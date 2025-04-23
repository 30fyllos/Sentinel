<?php

declare(strict_types=1);

namespace Drupal\sentinel_key;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\views\Plugin\views\field\Boolean;

/**
 * Provides an interface defining a sentinel key entity type.
 */
interface SentinelKeyInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {
  /**
   * Gets the API key value.
   *
   * @return string
   *   The API key string.
   */
  public function getApiKey(): string;

  /**
   * Generate the API key value.
   *
   * @return $this
   */
  public function genApiKey(): static;

  /**
   * Gets the hashed API key value.
   *
   * @return string
   *   The API key string.
   */
  public function getHashedApiKey(): string;

  /**
   * The API key status.
   *
   * @return bool
   *   The API key status.
   */
  public function isEnabled(): bool;

  /**
   * The API key Block/Unblock status.
   *
   * @return bool
   *   The API key block status.
   */
  public function isBlocked(): bool;

  /**
   * Toggle API Key status.
   *
   * @return $this
   */
  public function toggleBlock(): static;

  /**
   * Get API key expiration timestamp.
   *
   * @return int
   *   The timestamp.
   */
  public function getExpirationTimestamp(): int;

  /**
   * The API key expiration status.
   *
   * @return bool
   *   The API key status.
   */
  public function isExpired(): bool;
}
