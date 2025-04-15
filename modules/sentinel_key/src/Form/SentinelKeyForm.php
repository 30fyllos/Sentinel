<?php

declare(strict_types=1);

namespace Drupal\sentinel_key\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the sentinel key entity edit forms.
 */
final class SentinelKeyForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    // Only users with 'administer sentinel_key' can edit the owner field.
    if (!$this->currentUser()->hasPermission('administer sentinel_key')) {
      // Option 1: Remove the field so it isn’t editable.
      if (isset($form['uid'])) {
        $form['uid']['#access'] = FALSE;
      }
      // Option 2: Or, display it as a disabled element.
      // if (isset($form['uid'])) {
      //   $form['uid']['#disabled'] = TRUE;
      // }
    }

    return $form;
  }

  /**
   * Helper method to get the current user.
   */
  protected function currentUser() {
    return \Drupal::currentUser();
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->toLink()->toString()];
    $logger_args = [
      '%label' => $this->entity->label(),
      'link' => $this->entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New sentinel key %label has been created.', $message_args));
        $this->logger('sentinel_key')->notice('New sentinel key %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The sentinel key %label has been updated.', $message_args));
        $this->logger('sentinel_key')->notice('The sentinel key %label has been updated.', $logger_args);
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    $form_state->setRedirectUrl($this->entity->toUrl());

    return $result;
  }

}
