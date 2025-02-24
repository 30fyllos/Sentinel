<?php

declare(strict_types=1);

namespace Drupal\sentinel_key\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\sentinel_key\SentinelKeyInterface;
use Drupal\user\EntityOwnerTrait;
use Random\RandomException;

/**
 * Defines the sentinel key entity class.
 *
 * @ContentEntityType(
 *   id = "sentinel_key",
 *   label = @Translation("Sentinel Key"),
 *   label_collection = @Translation("Sentinel Keys"),
 *   label_singular = @Translation("sentinel key"),
 *   label_plural = @Translation("sentinel keys"),
 *   label_count = @PluralTranslation(
 *     singular = "@count sentinel keys",
 *     plural = "@count sentinel keys",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\sentinel_key\SentinelKeyListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\sentinel_key\SentinelKeyAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\sentinel_key\Form\SentinelKeyForm",
 *       "edit" = "Drupal\sentinel_key\Form\SentinelKeyForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "sentinel_key",
 *   admin_permission = "administer sentinel_key",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/content/sentinel-key",
 *     "add-form" = "/sentinel-key/add",
 *     "canonical" = "/sentinel-key/{sentinel_key}",
 *     "edit-form" = "/sentinel-key/{sentinel_key}/edit",
 *     "delete-form" = "/sentinel-key/{sentinel_key}/delete",
 *     "delete-multiple-form" = "/admin/content/sentinel-key/delete-multiple",
 *   },
 *   field_ui_base_route = "entity.sentinel_key.settings",
 * )
 */
final class SentinelKey extends ContentEntityBase implements SentinelKeyInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * The api key service.
   *
   * @return mixed
   */
  protected function apiKeyManager(): mixed
  {
    return \Drupal::service('sentinel_key.manager');
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
    if ($this->isNew()) {
      $apiKey = base64_encode(random_bytes(32));
      $this->set('api_key', hash('sha256', $apiKey));
      $this->set('data', $this->apiKeyManager()->encryptValue($apiKey));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['affiliation'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Affiliated to'))
      ->setSetting('target_type', 'group')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    // API key string.
    $fields['api_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('API Key'))
      ->setDescription(t('The API key string.'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE);

    // Additional data field.
    $fields['data'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Data'))
      ->setDescription(t('Additional data related to the API key.'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'decrypted_field_formatter',
        'weight' => -5,
      ]);

    // Boolean field to mark if the key is blocked.
    $fields['blocked'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Blocked'))
      ->setDescription(t('Indicates whether the API key is blocked.'))
      ->setDefaultValue(FALSE);

    // Expiration timestamp (optional).
    $fields['expires'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Expires'))
      ->setDescription(t('The expiration timestamp for the API key.'))
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_custom',
        'settings' => [
          'timezone_override' => '',
          'date_format' => 'd/m/Y'
        ],
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the key was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the key was last edited.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): string
  {
    return $this->get('data')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getHashedApiKey(): string
  {
    return $this->get('api_key')->value;
  }

  /**
   * {@inheritdoc}
   * @throws RandomException
   */
  public function genApiKey(string $api_key): static
  {
    $apiKey = base64_encode(random_bytes(32));
    $this->set('api_key', hash('sha256', $apiKey));
    $this->set('data', $this->apiKeyManager()->encryptValue($apiKey));
    return $this;
  }

}
