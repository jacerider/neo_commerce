<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Checkout;

use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Resolves the words the single-page checkout prints.
 *
 * Like the cart copy: one set of defaults (`checkout_copy`) and optional
 * overrides per Commerce order type (`checkout_copy_types`), where an empty
 * override means "use the default". Section headings are not here: each
 * section is a checkout pane, and Commerce already lets a flow rename its
 * panes ("Display label" in the flow's pane settings).
 */
class CheckoutCopy {

  /**
   * The keys, in the order the settings form shows them.
   */
  public const KEYS = [
    'kicker',
    'title',
    'intro',
    'summary_title',
    'summary_toggle_label',
    'place_order_label',
    'secure_note',
  ];

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
  ) {}

  /**
   * Returns the copy for one order type's checkout.
   *
   * @param string $orderTypeId
   *   The order type id.
   *
   * @return array<string, string>
   *   Every key in self::KEYS, with the per-type value or the default.
   */
  public function forOrderType(string $orderTypeId): array {
    $settings = $this->settings->getActive();
    $defaults = (array) $settings->getValue('checkout_copy');
    $types = (array) $settings->getValue('checkout_copy_types');
    $overrides = (array) ($types[$orderTypeId] ?? []);

    $copy = [];
    foreach (self::KEYS as $key) {
      $value = trim((string) ($overrides[$key] ?? ''));
      $copy[$key] = $value !== ''
        ? $value
        : trim((string) ($defaults[$key] ?? ''));
    }
    return $copy;
  }

}
