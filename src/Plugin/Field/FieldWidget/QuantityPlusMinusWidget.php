<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\Field\FieldWidget;

use Drupal\commerce_order\Plugin\Field\FieldWidget\QuantityWidget;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Renders an order item quantity with plus/minus controls.
 *
 * Lived in the neo module until now, which extends a commerce_order class
 * while declaring no commerce dependency: plugin discovery reflects the
 * class, so on a site without Commerce loading it fatals on the missing
 * parent. The plugin id is unchanged, so form displays already configured to
 * use it keep working once this module is enabled.
 */
#[FieldWidget(
  id: 'quantity_plus_minus',
  label: new TranslatableMarkup('Quantity | Plus Minus'),
  field_types: [
    'integer',
    'decimal',
    'float',
  ],
)]
class QuantityPlusMinusWidget extends QuantityWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['value']['#type'] = 'number_plus_minus';
    return $element;
  }

}
