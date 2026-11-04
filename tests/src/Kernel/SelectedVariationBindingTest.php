<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_commerce\Hook\ProductHooks;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests binding props through a product's selected variation.
 */
#[Group('neo_commerce')]
class SelectedVariationBindingTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'neo',
    'neo_build',
    'neo_color',
    'neo_icon',
    'neo_settings',
    'neo_modal',
    'neo_tooltip',
    'neo_alchemist',
    'neo_commerce',
  ];

  /**
   * The field matcher the entity value provider resolves bindings with.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherField
   */
  protected $matcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpCurrentUser([], ['view commerce_product']);
    $this->matcher = $this->container->get('neo_alchemist.matcher_field');
  }

  /**
   * It resolves variations to the variation selected by the v argument.
   */
  public function testResolvesSelectedVariation(): void {
    $product = $this->createProduct(['ONE', 'TWO']);
    $second = $product->getVariations()[1];
    $this->pushRequest($second->id());

    $this->assertSame('TWO', $this->sku($product));
  }

  /**
   * It resolves variations to the default variation with no v argument.
   */
  public function testResolvesDefaultVariation(): void {
    $product = $this->createProduct(['ONE', 'TWO']);

    $this->assertSame('ONE', $this->sku($product));
  }

  /**
   * It ignores a v argument naming a variation of another product.
   */
  public function testIgnoresOtherProductsVariation(): void {
    $product = $this->createProduct(['ONE', 'TWO']);
    $other = $this->createProduct(['ELSEWHERE']);
    $this->pushRequest($other->getDefaultVariation()->id());

    $this->assertSame('ONE', $this->sku($product));
  }

  /**
   * It varies the render cache by url.query_args:v.
   */
  public function testVariesByVariationArgument(): void {
    $product = $this->createProduct(['ONE']);
    $cacheability = new CacheableMetadata();
    $this->sku($product, $cacheability);

    $this->assertContains('url.query_args:v', $cacheability->getCacheContexts());
    $this->assertContains('commerce_product_variation:' . $product->getDefaultVariation()->id(), $cacheability->getCacheTags());
  }

  /**
   * It leaves references other than a product's variations alone.
   */
  public function testOtherReferencesUntouched(): void {
    $product = $this->createProduct(['ONE']);
    $cacheability = new CacheableMetadata();
    $value = $this->matcher->getEntityValue($product, 'stores.name', [], [], TRUE, $cacheability);

    $this->assertSame($this->store->getName(), $value[0]['value']);
    $variationTag = 'commerce_product_variation:' . $product->getDefaultVariation()->id();
    $this->assertNotContains($variationTag, $cacheability->getCacheTags());
  }

  /**
   * It marks component instances that read through the selected variation.
   */
  public function testMarksComponents(): void {
    $hooks = $this->container->get(ProductHooks::class);
    $product = $this->createProduct(['ONE']);
    $component = $this->createMock(Component::class);
    $component->method('uuid')->willReturn('instance-uuid');
    $component->method('getTargetEntity')->willReturn($product);

    // Reading the product alone still carries Commerce's v context.
    $cacheability = new CacheableMetadata();
    $this->matcher->getEntityValue($product, 'title', [], [], TRUE, $cacheability);
    $build = ['#props' => ['attributes' => new Attribute()]];
    $hooks->componentBuildAlter($build, $cacheability, $component);
    $this->assertFalse($build['#props']['attributes']->hasAttribute(ProductHooks::MARKER), 'A component not bound to a variation is not marked.');

    $this->sku($product, $cacheability);
    $hooks->componentBuildAlter($build, $cacheability, $component);
    $this->assertSame('instance-uuid', $build['#props']['attributes'][ProductHooks::MARKER]->value());
  }

  /**
   * Resolves `variations.sku` the way the entity value provider does.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   (optional) Collects the binding's cacheability.
   *
   * @return string|null
   *   The SKU the binding resolves to.
   */
  protected function sku(ProductInterface $product, ?CacheableMetadata $cacheability = NULL): ?string {
    $value = $this->matcher->getEntityValue($product, 'variations.sku', [], [], TRUE, $cacheability);
    return $value[0]['value'] ?? NULL;
  }

  /**
   * Makes the current request carry a v query argument.
   *
   * @param int|string $variationId
   *   The variation id.
   */
  protected function pushRequest(int|string $variationId): void {
    $request = Request::create('/', 'GET', ['v' => $variationId]);
    $request->setSession($this->container->get('session'));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Creates a published product with one variation per SKU.
   *
   * @param string[] $skus
   *   The SKUs.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface
   *   The product.
   */
  protected function createProduct(array $skus): ProductInterface {
    $variations = [];
    foreach ($skus as $sku) {
      $variation = ProductVariation::create([
        'type' => 'default',
        'sku' => $sku,
        'price' => new Price('9.99', 'USD'),
        'status' => 1,
      ]);
      $variation->save();
      $variations[] = $variation;
    }
    $product = Product::create([
      'type' => 'default',
      'title' => $this->randomMachineName(),
      'stores' => [$this->store],
      'variations' => $variations,
      'status' => 1,
    ]);
    $product->save();
    return $product;
  }

}
