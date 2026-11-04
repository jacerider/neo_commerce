<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Product;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Finds the products shown as related to a product.
 *
 * Editor-picked products from the configured related field win. With none,
 * other published products sharing the product's first category fill in,
 * weight then title, up to the limit.
 */
class RelatedProducts {

  /**
   * Constructs the finder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the related products of a product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param int $limit
   *   The most products to return from the category fallback.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Receives what the list depends on.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface[]
   *   The related products, possibly none.
   */
  public function load(ProductInterface $product, int $limit, CacheableMetadata $cacheability): array {
    $settings = $this->settings->getActive();
    $cacheability->addCacheableDependency($product)
      ->addCacheTags(['config:neo_commerce.settings']);

    $relatedField = (string) $settings->getValue('product_related_field');
    if ($relatedField !== '' && $product->hasField($relatedField)) {
      $picked = [];
      foreach ($product->get($relatedField)->referencedEntities() as $candidate) {
        if ($this->shows($candidate, $product)) {
          $cacheability->addCacheableDependency($candidate);
          $picked[] = $candidate;
        }
      }
      if ($picked) {
        return $picked;
      }
    }

    $categoryField = (string) $settings->getValue('product_category_field');
    if ($categoryField === '' || !$product->hasField($categoryField) || $product->get($categoryField)->isEmpty()) {
      return [];
    }
    // Any product joining or leaving the category changes the list.
    $cacheability->addCacheTags(['commerce_product_list']);
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition($categoryField, $product->get($categoryField)->target_id)
      ->condition('product_id', $product->id(), '<>')
      ->range(0, $limit);
    if ($product->hasField('field_weight')) {
      $query->sort('field_weight');
    }
    $query->sort('title');
    $related = [];
    foreach ($storage->loadMultiple($query->execute()) as $candidate) {
      if ($this->shows($candidate, $product)) {
        $cacheability->addCacheableDependency($candidate);
        $related[] = $candidate;
      }
    }
    return $related;
  }

  /**
   * Whether a candidate may be shown beside the product.
   */
  protected function shows(mixed $candidate, ProductInterface $product): bool {
    return $candidate instanceof ProductInterface
      && $candidate->id() !== $product->id()
      && $candidate->isPublished()
      && $candidate->access('view');
  }

}
