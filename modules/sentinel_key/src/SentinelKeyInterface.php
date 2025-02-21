<?php

declare(strict_types=1);

namespace Drupal\sentinel_key;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

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
   * @param string $api_key
   *   The API key string.
   *
   * @return $this
   */
  public function genApiKey(string $api_key): static;

  /**
   * Gets the hashed API key value.
   *
   * @return string
   *   The API key string.
   */
  public function getHashedApiKey(): string;
}
