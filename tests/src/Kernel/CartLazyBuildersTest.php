<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart summary build.
 */
#[Group('neo_commerce')]
class CartLazyBuildersTest extends CartKernelTestBase {

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
   * A product variation.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $variation;

  /**
   * The lazy builder under test.
   *
   * @var \Drupal\neo_commerce\Cart\CartLazyBuilders
   */
  protected $lazyBuilders;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_commerce']);

    $variationType = ProductVariationType::load('default');
    $variationType->setGenerateTitle(FALSE);
    $variationType->save();

    $this->variation = ProductVariation::create([
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'title' => 'A thing',
      'price' => new Price('9.99', 'USD'),
      'status' => 1,
    ]);
    $this->variation->save();

    $this->lazyBuilders = $this->container->get('neo_commerce.cart_lazy_builders');
  }

  /**
   * An empty cart still renders, and says so.
   */
  public function testEmptyCart(): void {
    $build = $this->lazyBuilders->cartBlock();

    $this->assertSame('neo_commerce:cart_quick', $build['#component']);
    $this->assertSame(0, $build['#props']['count']);
    $this->assertSame('0 items', $build['#props']['count_text']);
    $this->assertSame('', $build['#props']['variation']);
    $this->assertSame('Cart, 0 items', $build['#props']['link_label']);
  }

  /**
   * Quantities are summed across every line in the cart.
   */
  public function testCountsQuantities(): void {
    $this->addToCart(3);
    $build = $this->lazyBuilders->cartBlock();

    $this->assertSame(3, $build['#props']['count']);
    $this->assertSame('3 items', $build['#props']['count_text']);
    // The accessible name has to name the link, not just the quantity.
    $this->assertSame('Cart, 3 items', $build['#props']['link_label']);
  }

  /**
   * A single item is announced in the singular.
   */
  public function testSingularCount(): void {
    $this->addToCart(1);
    $build = $this->lazyBuilders->cartBlock();

    $this->assertSame('1 item', $build['#props']['count_text']);
  }

  /**
   * The cart's cache tags and contexts reach the render array.
   *
   * Commerce 3.3.8 assembles exactly this metadata and then returns a #cache
   * holding only the cart context, so every tag and the user and session
   * contexts are dropped. A cart summary that outlives its cart is the visible
   * symptom.
   */
  public function testCacheability(): void {
    $this->addToCart(1);
    $cart = $this->cartProvider->getCart('default', $this->store);
    $build = $this->lazyBuilders->cartBlock();

    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('session', $build['#cache']['contexts']);
    $this->assertContains('cart', $build['#cache']['contexts']);
    foreach ($cart->getCacheTags() as $tag) {
      $this->assertContains($tag, $build['#cache']['tags']);
    }
  }

  /**
   * An empty cart is taggable too, so filling it invalidates the summary.
   */
  public function testEmptyCartIsStillTagged(): void {
    $cart = $this->cartProvider->createCart('default', $this->store);
    $build = $this->lazyBuilders->cartBlock();

    foreach ($cart->getCacheTags() as $tag) {
      $this->assertContains($tag, $build['#cache']['tags']);
    }
  }

  /**
   * Hiding an empty cart returns nothing but keeps its cacheability.
   *
   * Driven through a variation rather than by editing the base config,
   * because the repository resolves the active settings once per request and
   * this test's builder has already been handed them.
   */
  public function testHiddenWhenEmpty(): void {
    $this->createVariation('neo_commerce_hidden', 'Hidden', [
      'cart_show_if_empty' => FALSE,
    ]);

    $build = $this->lazyBuilders->cartBlock('neo_commerce_hidden');

    $this->assertArrayNotHasKey('#component', $build);
    // The block has to stay invalidatable while it is rendering nothing, or
    // the first item added would never bring it back.
    $this->assertContains('cart', $build['#cache']['contexts']);
  }

  /**
   * A variation drives the build, and is echoed back for the refresh to find.
   */
  public function testVariation(): void {
    $this->createVariation('neo_commerce_mini', 'Mini', ['cart_label' => 'Bag']);

    $build = $this->lazyBuilders->cartBlock('neo_commerce_mini');

    $this->assertSame('neo_commerce_mini', $build['#props']['variation']);
    $this->assertSame('Bag', $build['#props']['label']);
    $this->assertSame('Bag, 0 items', $build['#props']['link_label']);
  }

  /**
   * An id that no longer names a variation falls back to the base settings.
   *
   * The id round-trips through the client as a lazy builder argument, so a
   * stale or invented one must not be able to break the page.
   */
  public function testUnknownVariationFallsBack(): void {
    $build = $this->lazyBuilders->cartBlock('nope');

    $this->assertSame('Cart, 0 items', $build['#props']['link_label']);
  }

  /**
   * Creates a neo_commerce settings variation.
   *
   * @param string $id
   *   The variation id.
   * @param string $label
   *   The variation label.
   * @param array $settings
   *   The settings the variation overrides.
   */
  protected function createVariation(string $id, string $label, array $settings): void {
    $this->container->get('entity_type.manager')
      ->getStorage('neo_settings')
      ->create([
        'id' => $id,
        'label' => $label,
        'plugin' => 'neo_commerce',
        'settings' => $settings,
      ])
      ->save();
  }

  /**
   * Adds the test variation to the current cart.
   *
   * @param int $quantity
   *   The quantity to add.
   */
  protected function addToCart(int $quantity): void {
    $cart = $this->cartProvider->createCart('default', $this->store);
    $orderItem = $this->container->get('entity_type.manager')
      ->getStorage('commerce_order_item')
      ->createFromPurchasableEntity($this->variation, [
        'quantity' => $quantity,
      ]);
    $this->cartManager->addOrderItem($cart, $orderItem);
  }

}
