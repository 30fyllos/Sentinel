<?php

namespace Drupal\sentinel_key\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'expiration_timestamp' field type.
 *
 * @FieldType(
 *   id = "expiration_timestamp",
 *   label = @Translation("Expiration timestamp"),
 *   description = @Translation("Stores an expiration timestamp as an integer."),
 *   default_widget = "expiration_timestamp_default",
 *   default_formatter = "expiration_timestamp_default"
 * )
 */
class ExpirationTimestampItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('integer')
      ->setLabel(t('Expiration timestamp'))
      ->setRequired(FALSE);

    $properties['unit'] = DataDefinition::create('string')
      ->setLabel(t('Duration unit'))
      ->setRequired(FALSE);

    $properties['duration'] = DataDefinition::create('integer')
      ->setLabel(t('Duration value'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'default' => 0,
        ],
        'unit' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => FALSE,
        ],
        'duration' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('value')->getValue();
    return empty($value);
  }

}
