<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Routing\RouteMatch;
use Drupal\neo_commerce\Cart\SimpleLayout;
use Drupal\neo_commerce\Hook\PageHooks;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * Tests when the cart and checkout use the simple page layout.
 */
#[Group('neo_commerce')]
class SimpleLayoutTest extends CartKernelTestBase {

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
   * A cart.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected OrderInterface $cart;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_commerce']);
    $variationType = ProductVariationType::load('default');
    $variationType->setGenerateTitle(FALSE);
    $variationType->save();
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'thing',
      'title' => 'A thing',
      'price' => new Price('9.99', 'USD'),
      'status' => 1,
    ]);
    $variation->save();
    $user = $this->createUser();
    $this->container->get('current_user')->setAccount($user);
    $this->cart = $this->cartProvider->createCart('default', $this->store, $user);
    $this->cartManager->addEntity($this->cart, $variation);
  }

  /**
   * Both settings are off by default; each covers only its own routes.
   */
  public function testAreaFollowsTheSettings(): void {
    $this->assertNull($this->layout()->area($this->route('commerce_cart.page')));

    $this->config('neo_commerce.settings')
      ->set('simple_layout_cart', TRUE)
      ->save();
    $layout = $this->layout();
    $this->assertSame('cart', $layout->area($this->route('commerce_cart.page')));
    $this->assertSame('cart', $layout->area($this->route('neo_commerce.cart_order')));
    $this->assertNull($layout->area($this->route('commerce_checkout.form')));
    $this->assertNull($layout->area($this->route('user.login')));
  }

  /**
   * The checkout setting adds both suggestions on checkout only.
   */
  public function testSuggestions(): void {
    $this->config('neo_commerce.settings')
      ->set('simple_layout_checkout', TRUE)
      ->save();
    $layout = $this->layout();

    $checkout = new PageHooks($layout, $this->route('commerce_checkout.form'));
    $suggestions = ['page__checkout'];
    $checkout->themeSuggestionsPageAlter($suggestions, []);
    $this->assertSame(
      ['page__checkout', 'page__simple', 'page__simple__checkout'],
      $suggestions
    );

    $cart = new PageHooks($layout, $this->route('commerce_cart.page'));
    $suggestions = [];
    $cart->themeSuggestionsPageAlter($suggestions, []);
    $this->assertSame([], $suggestions);
  }

  /**
   * Cart pages go on shopping; checkout goes back to its cart until done.
   */
  public function testBackLinks(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_copy.continue_url', '/market')
      ->set('cart_copy_types', ['default' => ['continue_label' => 'Shop the Market']])
      ->save();
    $layout = $this->layout();

    $cart = $layout->backLink($this->route('commerce_cart.page'));
    $this->assertSame('Shop the Market', $cart['label']);
    $this->assertSame('/market', $cart['url']->toString());

    $step = $layout->backLink($this->route('commerce_checkout.form', 'order_information'));
    $this->assertSame('Back to cart', $step['label']);
    $this->assertSame('/cart/' . $this->cart->id(), $step['url']->toString());

    $complete = $layout->backLink($this->route('commerce_checkout.form', 'complete'));
    $this->assertSame('Shop the Market', $complete['label']);
  }

  /**
   * Builds a route match carrying the cart.
   */
  protected function route(string $name, ?string $step = NULL): RouteMatch {
    $parameters = in_array($name, ['neo_commerce.cart_order', 'commerce_checkout.form'], TRUE)
      ? ['commerce_order' => $this->cart, 'step' => $step]
      : [];
    // RouteMatch keeps only the parameters the path declares.
    $path = $parameters ? '/x/{commerce_order}/{step}' : '/x';
    return new RouteMatch($name, new Route($path), $parameters);
  }

  /**
   * Returns the service, built after any settings the test wrote.
   *
   * The settings repository resolves once and keeps the result for the
   * request, and setUp() has already read it while filling the cart, so the
   * services holding it are dropped for the container to build again.
   */
  protected function layout(): SimpleLayout {
    foreach ([
      'neo_commerce.settings',
      'neo_commerce.cart_copy',
      'neo_commerce.cart_page_builder',
      'neo_commerce.simple_layout',
    ] as $id) {
      $this->container->set($id, NULL);
    }
    return $this->container->get('neo_commerce.simple_layout');
  }

}
