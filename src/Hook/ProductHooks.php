<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;

/**
 * Product display hooks for laying out products with Neo Alchemist.
 */
class ProductHooks {

  /**
   * Implements hook_ENTITY_TYPE_view_alter() for commerce_product.
   *
   * Commerce injects every variation field into the product build as a
   * `variation_*` child. A product laid out by a component tree renders the
   * variation fields it wants itself, so the injected copies are dropped.
   */
  #[Hook('commerce_product_view_alter')]
  public function productViewAlter(array &$build, ProductInterface $product, EntityViewDisplayInterface $display): void {
    $hasTree = FALSE;
    foreach (Element::children($build) as $key) {
      if (!str_starts_with($key, 'variation_') && !empty($build[$key]['#neo_component_tree'])) {
        $hasTree = TRUE;
        break;
      }
    }
    if (!$hasTree) {
      return;
    }
    foreach (Element::children($build) as $key) {
      if (str_starts_with($key, 'variation_')) {
        unset($build[$key]);
      }
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for field.
   *
   * A component tree field prints no wrapper of its own. On a variation it
   * needs one, because the replacement class Commerce adds on a variation
   * change lives on the field's attributes.
   */
  #[Hook('preprocess_field')]
  public function preprocessField(array &$variables): void {
    if (($variables['field_type'] ?? NULL) === 'neo_component_tree'
      && ($variables['entity_type'] ?? NULL) === 'commerce_product_variation') {
      $variables['tag'] = 'div';
    }
  }

}
