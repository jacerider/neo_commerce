<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Render\RenderContext;
use Drupal\neo_commerce\Controller\CartPageController;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests /cart, /cart/{order} and the cart overview.
 */
#[Group('neo_commerce')]
class CartPageControllerTest extends CartKernelTestBase {

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
    'file',
    'image',
    'neo_image',
    'neo_commerce',
  ];

  /**
   * A product variation.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $variation;

  /**
   * The shopper.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $shopper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_commerce']);
    OrderType::create([
      'id' => 'catering',
      'label' => 'Catering',
      'workflow' => 'order_default',
    ])->save();

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

    $this->shopper = $this->createUser();
    $this->container->get('current_user')->setAccount($this->shopper);
  }

  /**
   * No cart with anything in it: the overview's empty state.
   */
  public function testNoCartsIsTheEmptyOverview(): void {
    $this->createCart('default', []);

    $build = $this->controller()->page();

    $this->assertSame('neo_commerce:cart_overview', $build['#component']);
    $this->assertTrue($build['#props']['empty']);
    $this->assertSame([], $build['#props']['carts']);
  }

  /**
   * One cart: that cart's page, in place.
   */
  public function testOneCartIsItsPage(): void {
    $cart = $this->createCart('default', [1]);
    // An empty second cart does not count.
    $this->createCart('catering', []);

    $build = $this->controller()->page();

    $this->assertSame('view', $build['#type']);
    $this->assertSame('commerce_cart_form', $build['#name']);
    $this->assertSame([$cart->id()], $build['#arguments']);
  }

  /**
   * Two carts: the overview, one card each.
   */
  public function testTwoCartsIsTheOverview(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_copy_types', ['catering' => ['title' => 'Trays']])
      ->set('cart_explainer', 'Why two checkouts?')
      ->save();
    $market = $this->createCart('default', [2, 1]);
    $catering = $this->createCart('catering', [1]);

    $build = $this->controller()->page();

    $this->assertFalse($build['#props']['empty']);
    $cards = $build['#props']['carts'];
    $this->assertCount(2, $cards);
    $byTitle = array_column($cards, NULL, 'title');
    // The type label, not the site-wide "Your cart".
    $this->assertSame(3, $byTitle['Default']['count']);
    $this->assertSame('3 items', $byTitle['Default']['count_text']);
    $this->assertSame('$29.97', $byTitle['Default']['subtotal']);
    $this->assertSame('/cart/' . $market->id(), $byTitle['Default']['view_url']);
    $this->assertSame('/cart/' . $catering->id(), $byTitle['Trays']['view_url']);
    $this->assertSame(
      [2, 1],
      array_column($byTitle['Default']['thumbnails'], 'quantity')
    );
    $this->assertSame(
      'Why two checkouts?',
      (string) $build['#slots']['explainer']['#markup']
    );
  }

  /**
   * The thumbnail row is capped, with the rest counted.
   */
  public function testThumbnailCap(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_overview_thumbnail_limit', 2)
      ->save();
    $this->createCart('default', [1, 1, 1, 1]);
    $this->createCart('catering', [1]);

    $cards = $this->controller()->page()['#props']['carts'];
    $byTitle = array_column($cards, NULL, 'title');

    $this->assertCount(2, $byTitle['Default']['thumbnails']);
    $this->assertSame(2, $byTitle['Default']['more']);
    $this->assertSame(0, $byTitle['Catering']['more']);
  }

  /**
   * A cart page links back to the overview only when there is one.
   */
  public function testBackLinkOnlyWithSeveralCarts(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_explainer', 'Why two checkouts?')
      ->save();
    $market = $this->createCart('default', [1]);
    $builder = $this->container->get('neo_commerce.cart_page_builder');

    $alone = $this->inContext(fn () => $builder->buildEmpty($market));
    $this->assertArrayNotHasKey('back_url', $alone['#props']);
    $this->assertArrayNotHasKey('explainer', $alone['#slots']);

    $this->createCart('catering', [1]);
    $several = $this->inContext(fn () => $builder->buildEmpty($market));
    $this->assertSame('/cart', $several['#props']['back_url']);
    $this->assertSame('Back to your cart', $several['#props']['back_label']);
    $this->assertSame(
      'Why two checkouts?',
      (string) $several['#slots']['explainer']['#markup']
    );
  }

  /**
   * A cart's page is the shopper's own, and only while it is a cart.
   */
  public function testAccess(): void {
    $mine = $this->createCart('default', [1]);
    $controller = $this->controller();

    $this->assertTrue($controller->access($mine, $this->shopper)->isAllowed());

    $stranger = $this->createUser();
    $this->assertFalse($controller->access($mine, $stranger)->isAllowed());

    $this->cartProvider->finalizeCart($mine);
    $this->cartProvider->clearCaches();
    $this->assertFalse($controller->access($mine, $this->shopper)->isAllowed());
  }

  /**
   * Creates a cart for the shopper holding one line per quantity.
   *
   * @param string $type
   *   The order type.
   * @param int[] $quantities
   *   One quantity per line.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The cart.
   */
  protected function createCart(string $type, array $quantities): OrderInterface {
    $cart = $this->cartProvider->createCart($type, $this->store, $this->shopper);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('commerce_order_item');
    foreach ($quantities as $quantity) {
      $item = $storage->createFromPurchasableEntity($this->variation, [
        'quantity' => $quantity,
      ]);
      $this->cartManager->addOrderItem($cart, $item, FALSE);
    }
    return $cart;
  }

  /**
   * Returns the controller, with builds made inside a render context.
   */
  protected function controller(): CartPageController {
    return new class(
      $this->container->get('neo_commerce.cart_page_builder'),
      $this->container->get('commerce_cart.cart_provider'),
    ) extends CartPageController {

      /**
       * {@inheritdoc}
       */
      public function page(): array {
        return \Drupal::service('renderer')->executeInRenderContext(
          new RenderContext(),
          fn () => parent::page()
        );
      }

    };
  }

  /**
   * Runs a build inside a render context, as a request would.
   */
  protected function inContext(callable $build): array {
    return $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      $build
    );
  }

}
