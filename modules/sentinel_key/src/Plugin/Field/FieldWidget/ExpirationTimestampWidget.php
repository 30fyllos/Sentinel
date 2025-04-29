<?php

namespace Drupal\sentinel_key\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'expiration_timestamp_default' widget.
 *
 * @FieldWidget(
 *   id = "expiration_timestamp_default",
 *   label = @Translation("Duration selector"),
 *   field_types = {
 *     "expiration_timestamp"
 *   }
 * )
 */
class ExpirationTimestampWidget extends WidgetBase {

  /**
   * Helper method to determine if a value is expired.
   */
  private function isExpired(?int $timestamp): bool {
    return !empty($timestamp) && $timestamp < \Drupal::time()->getCurrentTime();
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];

    if ($this->isExpired($item->value)) {
      $form['#prefix'] = '<div class="messages messages--warning">' . $this->t('The current API key has expired. To continue using this key, please save the form. The expiration date will be renewed for the same duration.') . '</div>' . ($form['#prefix'] ?? '');
    }

    $form_state->setTemporaryValue("expiration_original_{$delta}", [
      'value' => $item->value,
      'duration' => $item->duration,
      'unit' => $item->unit,
    ]);

    $element['container'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Duration'),
      '#attributes' => ['class' => ['container-inline']],
    ];

    $element['container']['duration'] = [
      '#type' => 'number',
      '#min' => 0,
      '#max' => 100,
      '#size' => 6,
      '#default_value' => $item->duration ?? 0,
    ];

    $element['container']['unit'] = [
      '#type' => 'select',
      '#options' => [
        'days' => $this->t('Day(s)'),
        'months' => $this->t('Month(s)'),
        'years' => $this->t('Year(s)'),
      ],
      '#default_value' => $item->unit ?? 'days',
    ];

    $element['value'] = [
      '#type' => 'hidden',
      '#default_value' => $item->value ?? 0,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $massaged = [];
    $now = \Drupal::time()->getCurrentTime();

    foreach ($values as $delta => $value) {
      $duration = (int) ($value['container']['duration'] ?? 0);
      $unit = $value['container']['unit'] ?? 'days';

      $original = $form_state->getTemporaryValue("expiration_original_{$delta}") ?? [];
      $existing_value = $original['value'] ?? 0;
      $existing_duration = $original['duration'] ?? NULL;
      $existing_unit = $original['unit'] ?? NULL;

      $multiplier = match ($unit) {
        'days' => 86400,
        'months' => 86400 * 30,
        'years' => 86400 * 365,
        default => 0,
      };

      $timestamp = $existing_value;

      if ($duration > 0 && ($existing_value < $now || $duration != $existing_duration || $unit != $existing_unit)) {
        $timestamp = $now + ($duration * $multiplier);
      }
//      dd($now);
      $massaged[$delta] = [
        'value' => $timestamp,
        'unit' => $unit,
        'duration' => $duration,
      ];
    }
    return $massaged;
  }

}
