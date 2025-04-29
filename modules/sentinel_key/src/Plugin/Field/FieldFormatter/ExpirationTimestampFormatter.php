<?php

namespace Drupal\sentinel_key\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\TimestampFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'expiration_timestamp_default' formatter.
 *
 * @FieldFormatter(
 *   id = "expiration_timestamp_default",
 *   label = @Translation("Timestamp or Never"),
 *   field_types = {
 *     "expiration_timestamp"
 *   }
 * )
 */
class ExpirationTimestampFormatter extends TimestampFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);

    if(!$elements) {
      $elements[] = ['#markup' => $this->t('Never')];
    }

    return $elements;
  }

}
