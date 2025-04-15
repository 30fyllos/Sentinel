<?php

namespace Drupal\sentinel_key\Plugin\Menu\LocalTask;

use Drupal\Core\Menu\LocalTaskDefault;

/**
 * Dynamic title for Block/Unblock tab.
 */
class ToggleBlockLocalTask extends LocalTaskDefault {

  public function getTitle(?\Symfony\Component\HttpFoundation\Request $request = NULL) {
    $sentinel_key = $request->attributes->get('sentinel_key');

    if ($sentinel_key && $sentinel_key->isBlocked()) {
      return t('Unblock');
    }
    return t('Block');
  }

}
