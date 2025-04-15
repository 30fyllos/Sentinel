<?php

namespace Drupal\sentinel_key\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\sentinel_key\Entity\SentinelKey;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for blocking/unblocking an API key.
 */
class SentinelKeyBlockConfirmForm extends ContentEntityConfirmFormBase {

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
   * Constructs a new confirmation form.
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
  public function getQuestion() {
    $status = $this->entity->isBlocked() ? $this->t('unblock') : $this->t('block');
    return $this->t('Are you sure you want to @status this API key?', ['@status' => $status]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->entity->isBlocked() ? $this->t('Unblock') : $this->t('Block');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url
  {
    return $this->currentUser->hasPermission('administer sentinel_key') ?
      new Url('entity.sentinel_key.collection') :
      Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->entity->toggleBlock();
    $this->entity->save();

    $status = $this->entity->isBlocked() ? $this->t('blocked') : $this->t('unblocked');
    $this->messenger()->addStatus($this->t('The API key is now @status.', ['@status' => $status]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
