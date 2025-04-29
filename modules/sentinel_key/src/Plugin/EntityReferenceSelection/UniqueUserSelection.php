<?php

declare(strict_types=1);

namespace Drupal\sentinel_key\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\Plugin\EntityReferenceSelection\UserSelection;

/**
 * @todo Add plugin description here.
 */
#[EntityReferenceSelection(
  id: 'sentinel_key_user_selection',
  label: new TranslatableMarkup('Unique User Selection'),
  group: 'sentinel_key_user_selection',
  weight: 1,
  entity_types: ['user'],
)]
final class UniqueUserSelection extends UserSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS'): QueryInterface {
    $query = parent::buildEntityQuery($match, $match_operator);

    $route_match = \Drupal::service('current_route_match');

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = \Drupal::service('request_stack')->getCurrentRequest();

    $uidsToExclude = \Drupal::database()->select('sentinel_key', 'sk')
      ->fields('sk', ['uid'])
      ->distinct()
      ->execute()
      ->fetchCol();

    $sentinelKey = $route_match->getParameter('sentinel_key');
    if (!$sentinelKey && $request->query->get('entity_type') === 'sentinel_key') {
      $sentinelKey = \Drupal::entityTypeManager()
        ->getStorage('sentinel_key')
        ->load($request->query->get('entity_id'));
    }

    if (!$sentinelKey) {
      return $query;
    }

    // Exclude owner in editing mode.
    if (!$sentinelKey->isNew()) {
      $owner_id = $sentinelKey->getOwnerId();
      $uidsToExclude = array_diff($uidsToExclude, [$owner_id]);
    }

    // Always exclude Admin.
    if (!in_array('1', $uidsToExclude)) {
      $uidsToExclude[] = '1';
    }

    $query->condition('uid', $uidsToExclude, 'NOT IN');

    return $query;
  }

}
