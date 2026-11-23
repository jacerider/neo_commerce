<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_order\Entity\OrderType;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Url;
use Drupal\neo_commerce\Cart\CartCopy;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart page copy and its per-order-type fallbacks.
 */
#[Group('neo_commerce')]
class CartCopyTest extends CartKernelTestBase {

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
  }

  /**
   * An order type with no overrides reads the defaults.
   */
  public function testDefaults(): void {
    $copy = $this->copy()->forOrderType('default');

    $this->assertSame('Your cart', $copy['title']);
    $this->assertSame(
      'Taxes and delivery are calculated at checkout.',
      $copy['footnote']
    );
    // No checkout label in the copy: the cart form's label stands in.
    $this->assertSame('Checkout', $copy['checkout_label']);
    $this->assertNull($copy['continue_url']);
    $this->assertSame('', $copy['notice']);
    $this->assertSame(CartCopy::KEYS, array_keys($copy));
  }

  /**
   * A per-type value wins; an empty one falls back to the default.
   */
  public function testPerTypeOverridesFallBack(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_copy.intro', 'Default intro.')
      ->set('cart_copy.continue_url', '/market')
      ->set('cart_copy_types', [
        'catering' => [
          'title' => 'Catering',
          'intro' => '',
          'checkout_label' => 'Schedule & Pay',
          'notice' => 'Scheduled delivery, <strong>2-day minimum</strong>.<script>x</script>',
          'continue_url' => '/catering',
        ],
      ])
      ->save();

    $catering = $this->copy()->forOrderType('catering');
    $this->assertSame('Catering', $catering['title']);
    $this->assertSame('Default intro.', $catering['intro']);
    $this->assertSame('Schedule & Pay', $catering['checkout_label']);
    $this->assertInstanceOf(MarkupInterface::class, $catering['notice']);
    $this->assertSame(
      'Scheduled delivery, <strong>2-day minimum</strong>.x',
      (string) $catering['notice']
    );
    $this->assertInstanceOf(Url::class, $catering['continue_url']);
    $this->assertSame('/catering', $catering['continue_url']->toString());

    // The other type is untouched by catering's overrides.
    $default = $this->copy()->forOrderType('default');
    $this->assertSame('Your cart', $default['title']);
    $this->assertSame('/market', $default['continue_url']->toString());
  }

  /**
   * Overview cards use only a type's own title, else the type label.
   */
  public function testCardTitle(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_copy_types', ['catering' => ['title' => 'Trays']])
      ->save();

    $this->assertSame('Trays', $this->copy()->cardTitle('catering'));
    // Not the site-wide "Your cart": every card would read the same.
    $this->assertSame('Default', $this->copy()->cardTitle('default'));
  }

  /**
   * A missing title falls back to the order type label.
   */
  public function testEmptyDefaultTitleUsesOrderTypeLabel(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_copy.title', '')
      ->save();

    $this->assertSame(
      'Catering',
      $this->copy()->forOrderType('catering')['title']
    );
  }

  /**
   * No explainer is set by default.
   */
  public function testNoExplainerByDefault(): void {
    $this->assertNull($this->copy()->explainer());
  }

  /**
   * The overview copy and the explainer.
   *
   * Configured before the first read: the settings repository keeps what it
   * resolved for the rest of the request.
   */
  public function testOverviewAndExplainer(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_explainer', 'Why two checkouts? <strong>Catering</strong> is scheduled.')
      ->set('cart_overview.empty_cta_url', '<front>')
      ->save();

    $this->assertSame(
      'Why two checkouts? <strong>Catering</strong> is scheduled.',
      (string) $this->copy()->explainer()
    );
    $overview = $this->copy()->overview();
    $this->assertSame(CartCopy::OVERVIEW_KEYS, array_keys($overview));
    $this->assertSame('Your cart is empty', $overview['empty_title']);
    $this->assertInstanceOf(Url::class, $overview['empty_cta_url']);
  }

  /**
   * Links accept site paths and http(s) URLs only.
   */
  public function testUrl(): void {
    $this->assertNull(CartCopy::url(''));
    $this->assertNull(CartCopy::url('market'));
    $this->assertNull(CartCopy::url('javascript:alert(1)'));
    $this->assertNotNull(CartCopy::url('/market'));
    $this->assertNotNull(CartCopy::url('<front>'));
    $this->assertSame(
      'https://example.com/a',
      CartCopy::url('https://example.com/a')->toString()
    );
  }

  /**
   * Per-type copy saves in config and in a variation.
   *
   * Kernel tests validate config against its schema on save, so this is
   * what proves the sequence of copy mappings is declared.
   */
  public function testSchema(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_copy_types', ['catering' => ['title' => 'Trays']])
      ->save();
    $this->container->get('entity_type.manager')
      ->getStorage('neo_settings')
      ->create([
        'id' => 'neo_commerce_wholesale',
        'label' => 'Wholesale',
        'plugin' => 'neo_commerce',
        'settings' => [
          'cart_copy_types' => ['catering' => ['title' => 'Bulk']],
        ],
      ])
      ->save();
    $this->assertSame(
      'Bulk',
      $this->container->get('neo_commerce.settings')
        ->get('neo_commerce_wholesale')
        ->getValue('cart_copy_types')['catering']['title']
    );
  }

  /**
   * Returns the copy resolver.
   */
  protected function copy(): CartCopy {
    return $this->container->get('neo_commerce.cart_copy');
  }

}
