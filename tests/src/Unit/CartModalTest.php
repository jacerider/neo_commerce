<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Unit;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Template\Attribute;
use Drupal\neo_commerce\Cart\CartModal;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart modal options and trigger wiring.
 */
#[Group('neo_commerce')]
#[CoversClass(CartModal::class)]
class CartModalTest extends UnitTestCase {

  /**
   * The options come straight from settings, preset included.
   *
   * The preset must survive as a name: neo_modal resolves it into concrete
   * values on the response, so flattening it here would lose it.
   */
  public function testOptions(): void {
    $options = CartModal::options($this->settings());

    $this->assertSame('neo_modal_shelf_top', $options['preset']);
    $this->assertSame('Shopping cart', $options['title']);
    $this->assertSame('shopping-cart', $options['icon']);
    $this->assertTrue($options['smartActions']);
    $this->assertFalse($options['inputFocus']);
  }

  /**
   * The trigger carries the four attributes neo_modal's link binder reads.
   */
  public function testTrigger(): void {
    $attributes = CartModal::trigger(
      ['href' => '/cart'],
      CartModal::options($this->settings())
    );
    $rendered = (string) new Attribute($attributes);

    // modal-link.ts binds this class, not core's use-ajax.
    $this->assertStringContainsString('use-modal-ajax', $rendered);
    $this->assertStringContainsString('data-dialog-type="modal"', $rendered);
    // Without this the link is fetched with POST.
    $this->assertStringContainsString('data-ajax-http-method="GET"', $rendered);
    // The href is the no-JS fallback and the ajax target both.
    $this->assertStringContainsString('href="/cart"', $rendered);
  }

  /**
   * The options travel under the `neo` key, which is the only one read.
   */
  public function testTriggerPayloadIsNamespaced(): void {
    $attributes = CartModal::trigger([], ['preset' => 'neo_modal_shelf_top']);

    $raw = $attributes['data-dialog-options'];
    $this->assertSame(
      ['neo' => ['preset' => 'neo_modal_shelf_top']],
      Json::decode($raw)
    );
  }

  /**
   * Existing attributes survive the wiring.
   */
  public function testTriggerPreservesExistingClasses(): void {
    $attributes = CartModal::trigger(['class' => ['cart-block--link']], []);
    $rendered = (string) new Attribute($attributes);

    $this->assertStringContainsString('cart-block--link', $rendered);
    $this->assertStringContainsString('use-modal-ajax', $rendered);
  }

  /**
   * A settings double returning the shipped defaults.
   */
  protected function settings(): SettingsInterface {
    $values = [
      'modal_preset' => 'neo_modal_shelf_top',
      'modal_title' => 'Shopping cart',
      'modal_icon' => 'shopping-cart',
      'modal_smart_actions' => TRUE,
      'modal_input_focus' => FALSE,
    ];
    $settings = $this->createMock(SettingsInterface::class);
    $settings->method('getValue')->willReturnCallback(
      static fn ($key) => $values[$key] ?? NULL
    );
    return $settings;
  }

}
