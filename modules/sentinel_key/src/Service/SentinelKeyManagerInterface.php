<?php

namespace Drupal\sentinel_key\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\sentinel_key\Entity\SentinelKey;
use Random\RandomException;

/**
 * Interface for the API Key Manager service.
 *
 * Provides methods for encrypting values, generating and revoking API keys,
 * tracking usage and rate limits, and more.
 */
interface SentinelKeyManagerInterface {



  /**
   * Load an environment variable from .env file.
   *
   * @return string
   *    The env value.
   *
   */
  public function getEnvValue(): string;

  /**
   * Encrypts a value using AES-256.
   *
   * @param string $value
   *   The value to encrypt.
   *
   * @return string
   *   The encrypted value.
   *
   * @throws \Exception
   */
  public function encryptValue(string $value): string;

  /**
   * Decrypts a value using AES-256.
   *
   * @param string $encryptedValue
   *   The encrypted value.
   *
   * @return false|string
   *   The decrypted value, or FALSE on failure.
   */
  public function decryptValue(string $encryptedValue): false|string;

  /**
   * Forces regeneration of all API keys.
   *
   * @return int
   *
   * @throws RandomException
   */
  public function forceRegenerateAllKeys(): int;

  /**
   * Checks if a user has an API key.
   *
   * @param AccountInterface|string $account
   *   The user account or user ID.
   *
   * @return bool
   */
  public function hasApiKey(AccountInterface|string $account): bool;

  /**
   * Checks if the API key has exceeded the rate limit.
   *
   * @param int $keyId
   *   The API key ID.
   *
   * @return bool
   *   TRUE if the rate limit is exceeded, FALSE otherwise.
   */
  public function checkRateLimit(int $keyId): bool;

  /**
   * Blocks an API key after too many failed attempts.
   *
   * @param int $keyId
   *   The API key ID.
   *
   * @return bool
   *   TRUE if the key has been blocked, FALSE otherwise.
   */
  public function blockFailedAttempt(int $keyId): bool;

  /**
   * Reset failure window on success.
   *
   * @param int $keyId
   *   The API key ID.
   *
   * @return void
   */
  public function resetFailureWindow(int $keyId): void;

  /**
   * Generates API keys for all users without one, optionally filtered by roles.
   *
   * @param array $roles
   *   An array of role IDs. If empty, no filtering is applied.
   * @param int|null $expires
   *   (Optional) Expiration timestamp.
   *
   * @return int
   *   The number of API keys generated.
   *
   * @throws RandomException
   */
//  public function generateApiKeysForAllUsers(array $roles = [], ?int $expires = NULL): int;
}
