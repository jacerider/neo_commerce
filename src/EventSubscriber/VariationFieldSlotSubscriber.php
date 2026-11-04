<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\EventSubscriber;

use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\neo_commerce\Product\VariationFieldRenderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Replaces every variation field slot when the shopper picks a variation.
 *
 * Commerce re-renders variation fields through the product's view mode. A
 * slot names its own display, so each configured slot is rebuilt here with
 * that display and swapped by its own class. A slot that is not on the page
 * simply matches nothing.
 */
class VariationFieldSlotSubscriber implements EventSubscriberInterface {

  /**
   * The slot plugin this subscriber replaces.
   */
  const PLUGIN_ID = 'product_variation_field';

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\neo_commerce\Product\VariationFieldRenderer $renderer
   *   The variation field renderer.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected VariationFieldRenderer $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProductEvents::PRODUCT_VARIATION_AJAX_CHANGE => 'onVariationChange',
    ];
  }

  /**
   * Adds a replace command for every configured variation field slot.
   *
   * @param \Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent $event
   *   The event.
   */
  public function onVariationChange(ProductVariationAjaxChangeEvent $event): void {
    $variation = $event->getProductVariation();
    $product = $variation->getProduct();
    if (!$product) {
      return;
    }
    $response = $event->getResponse();
    foreach ($this->slotSettings() as $settings) {
      $build = $this->renderer->build($product, $variation, $settings);
      $selector = '.' . $this->renderer->replacementClass($product, $settings);
      $response->addCommand(new ReplaceCommand($selector, $build));
    }
  }

  /**
   * Collects the distinct settings of every variation field slot.
   *
   * @return array[]
   *   Slot settings (bundle, display, field), one per distinct combination.
   */
  protected function slotSettings(): array {
    $settings = [];
    $names = $this->configFactory->listAll('neo_alchemist.neo_component.');
    foreach ($this->configFactory->loadMultiple($names) as $config) {
      foreach ($config->get('settings.slots') ?? [] as $slot) {
        foreach ($slot['plugins'] ?? [] as $plugin) {
          if (($plugin['plugin'] ?? '') !== self::PLUGIN_ID) {
            continue;
          }
          $item = ($plugin['settings'] ?? []) + [
            'bundle' => '',
            'display' => '',
            'field' => '',
          ];
          $settings[implode(':', $item)] = $item;
        }
      }
    }
    return array_values($settings);
  }

}
