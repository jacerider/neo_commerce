<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Product;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Element;

/**
 * Renders one variation field the way a variation field slot is configured.
 *
 * Commerce's own renderer marks a field with a class built from the field
 * name alone and, on a variation change, swaps every such field for the one
 * the product's view mode produces. A slot names its own display, so it is
 * marked with a class of its own that also carries the bundle and display,
 * and is swapped by the variation field slot subscriber instead.
 */
class VariationFieldRenderer {

  /**
   * Constructs the renderer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $displayRepository
   *   The entity display repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityDisplayRepositoryInterface $displayRepository,
  ) {}

  /**
   * Builds a variation field for a slot's settings.
   *
   * Always returns an element carrying the replacement class, even when
   * there is nothing to show, so a later variation has a target to fill.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product being viewed.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation
   *   The variation to render.
   * @param array $settings
   *   The slot settings: bundle, display and field.
   *
   * @return array
   *   A render array.
   */
  public function build(ProductInterface $product, ?ProductVariationInterface $variation, array $settings): array {
    $class = $this->replacementClass($product, $settings);
    $build = $this->buildField($variation, $settings);
    if ($build === []) {
      $build = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => [$class]],
      ];
    }
    elseif (isset($build['#lazy_builder'])) {
      // A lazy built element cannot carry attributes.
      $build = [
        '#type' => 'container',
        '#attributes' => ['class' => [$class]],
        'field' => $build,
      ];
    }
    else {
      $build['#attributes']['class'][] = $class;
    }
    $build['#weight'] = 0;

    $cacheability = CacheableMetadata::createFromRenderArray($build)
      ->addCacheContexts([SelectedVariation::CACHE_CONTEXT])
      ->addCacheableDependency($product);
    if ($variation) {
      $cacheability->addCacheableDependency($variation);
    }
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Builds the field itself, or an empty array when there is none.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation
   *   The variation.
   * @param array $settings
   *   The slot settings.
   *
   * @return array
   *   The field render array, or [] when nothing should show.
   */
  protected function buildField(?ProductVariationInterface $variation, array $settings): array {
    $field = $settings['field'] ?? '';
    if (!$variation
      || $variation->bundle() !== ($settings['bundle'] ?? '')
      || !$variation->hasField($field)
      || $variation->get($field)->isEmpty()) {
      return [];
    }
    $display = $this->displayRepository->getViewDisplay(
      'commerce_product_variation',
      $variation->bundle(),
      $settings['display'] ?: 'default',
    );
    $options = $display->getComponent($field);
    if (!$options) {
      return [];
    }
    $build = $this->entityTypeManager
      ->getViewBuilder('commerce_product_variation')
      ->viewField($variation->get($field), $options);
    return Element::isEmpty($build) ? [] : $build;
  }

  /**
   * Builds the class a slot's output is found and replaced by.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param array $settings
   *   The slot settings.
   *
   * @return string
   *   The class.
   */
  public function replacementClass(ProductInterface $product, array $settings): string {
    return Html::getClass(implode('--', [
      'neo-commerce-variation-field',
      $settings['bundle'] ?? '',
      $settings['display'] ?? '',
      $settings['field'] ?? '',
      $product->id(),
    ]));
  }

}
