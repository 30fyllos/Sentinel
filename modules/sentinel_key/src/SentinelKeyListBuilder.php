<?php

declare(strict_types=1);

namespace Drupal\sentinel_key;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the sentinel key entity type.
 */
final class SentinelKeyListBuilder extends EntityListBuilder {

  public function getOperations(EntityInterface $entity): array
  {
    $operations = parent::getOperations($entity);

    if ($entity->access('update')) {
      $operations['regenerate_key'] = [
        'title' => t('Regenerate key'),
        'weight' => 8,
        'url' => $entity->toUrl('regenerate-key'),
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode([
            'width' => 600
          ])
        ],
      ];

      $operations['toggle_block'] = [
        'title' => $entity->isBlocked() ? t('Unblock') : t('Block'),
        'weight' => 9,
        'url' => $entity->toUrl('toggle-block'),
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode([
            'width' => 600
          ])
        ],
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['status'] = $this->t('Status');
    $header['blocked'] = $this->t('Blocked');
    $header['uid'] = $this->t('Author');
    $header['created'] = $this->t('Created');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\sentinel_key\SentinelKeyInterface $entity */
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['status'] = $entity->get('status')->value ? $this->t('Enabled') : $this->t('Disabled');
    $row['blocked'] = $entity->get('status')->value ? $this->t('Unblocked') : $this->t('Blocked');
    $username_options = [
      'label' => 'hidden',
      'settings' => ['link' => $entity->get('uid')->entity->isAuthenticated()],
    ];
    $row['uid']['data'] = $entity->get('uid')->view($username_options);
    $row['created']['data'] = $entity->get('created')->view(['label' => 'hidden']);
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);
    return $row + parent::buildRow($entity);
  }

}
