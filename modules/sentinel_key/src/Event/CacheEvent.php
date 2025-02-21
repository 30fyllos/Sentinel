<?php

namespace Drupal\sentinel_key\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that is dispatched when a new entity is created.
 */
class CacheEvent extends Event {

  /**
   * Event name for entity creation.
   */
  const FLUSH = 'sentinel_key.cache_flush';
}
