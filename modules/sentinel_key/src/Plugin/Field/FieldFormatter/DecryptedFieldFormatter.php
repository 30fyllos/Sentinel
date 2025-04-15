<?php

namespace Drupal\sentinel_key\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;

/**
 * Plugin implementation of the 'decrypted_field_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "decrypted_field_formatter",
 *   label = @Translation("Decrypted Field Formatter"),
 *   field_types = {
 *     "string",
 *     "string_long"
 *   }
 * )
 */
class DecryptedFieldFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // Process each field value.
    foreach ($items as $delta => $item) {
      // Decrypt the value using the service.
      $decrypted_value = \Drupal::service('sentinel_key.manager')->decryptValue($item->value);

      // Create a unique ID for the hidden container.
      $unique_id = 'decrypted-field-' . $delta . '-' . rand();

      // Build the output markup.
      $output = '<div>';
      $output .= '<a href="#" onclick="var el = document.getElementById(\'' . $unique_id . '\'); if (el.style.display === \'none\') { el.style.display = \'block\'; this.innerHTML = \'Hide key\'; } else { el.style.display = \'none\'; this.innerHTML = \'Show key\'; } return false;">Show key</a>';
      $output .= '<div id="' . $unique_id . '" style="display:none; margin-top:5px;">' . Html::escape($decrypted_value) . '</div>';
      $output .= '</div>';

      $elements[$delta] = [
        '#markup' => Markup::create($output),
      ];
    }

    return $elements;
  }

}
