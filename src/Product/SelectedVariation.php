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
   * Variations forced for a product, keyed by product id.
   *
   * @var array<int|string, \Drupal\commerce_product\Entity\ProductVariationInterface>
   */
  protected array $overrides = [];

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
    $variation = $this->overrides[$product->id()] ?? $storage->loadFromContext($product);
    if ($cacheability) {
      $cacheability->addCacheContexts([self::CACHE_CONTEXT]);
      if ($variation) {
        $cacheability->addCacheableDependency($variation);
      }
    }
    return $variation;
  }

  /**
   * Runs a callback with a product's selected variation forced.
   *
   * A variation change arrives as an ajax POST to the form's action, which
   * carries no `v`; the re-render takes the variation from Commerce's event
   * and forces it here for everything the callback builds.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation to treat as selected for its product.
   * @param callable $callback
   *   The callback.
   *
   * @return mixed
   *   The callback's return value.
   */
  public function with(ProductVariationInterface $variation, callable $callback): mixed {
    $productId = $variation->getProductId();
    $previous = $this->overrides[$productId] ?? NULL;
    $this->overrides[$productId] = $variation;
    try {
      return $callback();
    }
    finally {
      if ($previous) {
        $this->overrides[$productId] = $previous;
      }
      else {
        unset($this->overrides[$productId]);
      }
    }
  }

}
