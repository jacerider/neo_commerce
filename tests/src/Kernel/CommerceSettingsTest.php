<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the neo_commerce settings plugin and its config schema.
 */
#[Group('neo_commerce')]
class CommerceSettingsTest extends CartKernelTestBase {

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
  }

  /**
   * The shipped defaults are installed and readable through the repository.
   */
  public function testDefaults(): void {
    $settings = $this->container->get('neo_commerce.settings')->getActive();

    $this->assertTrue((bool) $settings->getValue('add_to_cart_ajax'));
    $this->assertSame('modal', $settings->getValue('add_to_cart_success'));
    $this->assertSame(
      'neo_modal_shelf_top',
      $settings->getValue('modal_preset')
    );
    // The shipped default before the theme takes the component over.
    $this->assertSame(
      'neo_commerce:cart_quick',
      $settings->getValue('cart_component')
    );
  }

  /**
   * Every key the settings form can write is declared in config/install.
   *
   * A key the form writes but the shipped config omits has no default, so it
   * reads back as NULL on a fresh site until someone visits the settings page
   * and presses save.
   */
  public function testEveryDefaultIsDeclared(): void {
    $config = $this->config('neo_commerce.settings')->getRawData();
    unset($config['_core']);

    $expected = [
      'add_to_cart_ajax',
      'add_to_cart_message',
      'add_to_cart_success',
      'add_to_cart_error_summary',
      'add_to_cart_guard',
      'cart_form_ajax',
      'cart_quantity_plus_minus',
      'cart_remove_icon',
      'cart_remove_label',
      'cart_update_label',
      'cart_checkout_label',
      'cart_component',
      'cart_label',
      'cart_icon',
      'cart_dropdown',
      'cart_show_if_empty',
      'modal_preset',
      'modal_title',
      'modal_icon',
      'modal_smart_actions',
      'modal_input_focus',
    ];
    sort($expected);
    $actual = array_keys($config);
    sort($actual);
    $this->assertSame($expected, $actual);
  }

  /**
   * A variation saves and resolves.
   *
   * Kernel tests validate config against its schema on save, so this is what
   * proves the neo_settings.settings.neo_commerce schema entry exists --
   * without it every variation on a real site fails validation.
   */
  public function testVariationSavesAndResolves(): void {
    $this->container->get('entity_type.manager')
      ->getStorage('neo_settings')
      ->create([
        'id' => 'neo_commerce_quote',
        'label' => 'Quote',
        'plugin' => 'neo_commerce',
        'settings' => [
          'cart_update_label' => 'Update Quote Request',
          'cart_checkout_label' => 'Review Quote Request',
        ],
      ])
      ->save();

    $repository = $this->container->get('neo_commerce.settings');
    $variation = $repository->get('neo_commerce_quote');

    $this->assertNotNull($variation);
    $this->assertSame(
      'Update Quote Request',
      $variation->getValue('cart_update_label')
    );
    // Unset keys fall through to the base settings rather than to NULL.
    $this->assertSame('modal', $variation->getValue('add_to_cart_success'));

    // Referencing a variation without the plugin-id prefix resolves too,
    // which is what a hand-written config value is likely to contain.
    $this->assertNotNull($repository->get('quote'));
  }

  /**
   * An unknown variation id falls back rather than fataling.
   */
  public function testUnknownVariationFallsBack(): void {
    $this->assertNull($this->container->get('neo_commerce.settings')->get('nope'));
  }

}
