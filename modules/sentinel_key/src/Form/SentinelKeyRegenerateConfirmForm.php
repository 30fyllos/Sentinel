<?php

declare(strict_types=1);

namespace Drupal\sentinel_key\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\sentinel_key\Entity\SentinelKey;
use Drupal\sentinel_key\Service\SentinelKeyManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Random\RandomException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirm form to regenerate an API key for a user.
 */
final class SentinelKeyRegenerateConfirmForm extends ContentEntityConfirmFormBase {

  /**
   * The entity being used by this form.
   *
   * @var SentinelKey|\Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionLogInterface
   */
  protected $entity;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The current user.
   *
   * @var AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs the form.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, AccountProxyInterface $currentUser) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to regenerate the API key for %label?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->currentUser->hasPermission('administer sentinel_key') ?
      new Url('entity.sentinel_key.collection') :
      Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   * @throws RandomException
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {

    $this->entity->genApiKey();
    $this->entity->save();

    $this->messenger()->addStatus($this->t('New API key generated for %label.', [
      '%label' => $this->entity->label()
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
