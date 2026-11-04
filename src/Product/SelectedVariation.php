<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Product;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves the variation the shopper has selected on a product page.
 *
 * Commerce owns the rule: the `v` query argument names the variation, a
 * variation of another product or one the user cannot view is ignored, and
 * the default variation is the fallback. This never parses `v` itself.
 */
class SelectedVariation {

  /**
   * The cache context a selected variation varies by.
   */
  const CACHE_CONTEXT = 'url.query_args:v';

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads the variation the current request selects for a product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   (optional) Receives the cache context and the variation's tags.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The selected variation, or NULL when the product has none.
   */
  public function load(ProductInterface $product, ?CacheableMetadata $cacheability = NULL): ?ProductVariationInterface {
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $storage */
    $storage = $this->entityTypeManager
      ->getStorage('commerce_product_variation');
    $variation = $storage->loadFromContext($product);
    if ($cacheability) {
      $cacheability->addCacheContexts([self::CACHE_CONTEXT]);
      if ($variation) {
        $cacheability->addCacheableDependency($variation);
      }
    }
    return $variation;
  }

}
