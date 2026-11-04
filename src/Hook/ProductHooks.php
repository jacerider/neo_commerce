<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_commerce\Product\SelectedVariation;

/**
 * Product display hooks for laying out products with Neo Alchemist.
 */
class ProductHooks {

  /**
   * The attribute that marks a component bound to the selected variation.
   *
   * Its value is the component instance uuid, which is what a variation
   * change re-renders it by.
   */
  const MARKER = 'data-neo-commerce-variation';

  /**
   * Constructs the hook handler.
   *
   * @param \Drupal\neo_commerce\Product\SelectedVariation $selectedVariation
   *   The selected variation resolver.
   */
  public function __construct(
    protected SelectedVariation $selectedVariation,
  ) {}

  /**
   * Implements hook_neo_alchemist_reference_alter().
   *
   * A binding that walks a product's `variations` field follows the variation
   * the shopper selected rather than the first one, so a prop bound to
   * `variations > field_media` shows the selected variation's images.
   */
  #[Hook('neo_alchemist_reference_alter')]
  public function referenceAlter(?EntityInterface &$target, EntityReferenceFieldItemListInterface $field, CacheableMetadata $cacheability): void {
    $product = $field->getEntity();
    if ($field->getName() !== 'variations' || !$product instanceof ProductInterface) {
      return;
    }
    $target = $this->selectedVariation->load($product, $cacheability);
  }

  /**
   * Implements hook_neo_component_build_alter().
   *
   * Marks every product component whose props were resolved through one of
   * its product's variations. Reading a variation adds that variation's cache
   * tag to the component, which is the signal. The `v` cache context is not:
   * Commerce adds it to every product, so it would mark everything.
   */
  #[Hook('neo_component_build_alter')]
  public function componentBuildAlter(array &$build, CacheableMetadata $cacheability, Component $component): void {
    $attributes = $build['#props']['attributes'] ?? NULL;
    if (!$attributes instanceof Attribute) {
      return;
    }
    $product = $component->getTargetEntity();
    if (!$product instanceof ProductInterface) {
      return;
    }
    $tags = array_map(
      fn ($id) => 'commerce_product_variation:' . $id,
      $product->getVariationIds(),
    );
    if (array_intersect($tags, $cacheability->getCacheTags())) {
      $attributes->setAttribute(self::MARKER, $component->uuid());
    }
  }

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
