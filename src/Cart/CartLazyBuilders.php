<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTrait;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Builds the cart summary as a single directory component.
 *
 * This deliberately composes Commerce's CartLazyBuilders rather than
 * extending it. The parent signature is cartBlock(bool, bool); anything this
 * needs to accept instead -- a settings variation id -- is an incompatible
 * override and a fatal, which is why the module this replaces was reduced to
 * bolting extra optional arguments onto the end.
 *
 * Composing also lets us fix a Commerce bug in passing: 3.3.8 assembles a
 * CacheableMetadata with user/session/cart contexts and every cart's tags,
 * then returns `#cache` containing only the cart context and drops the rest.
 */
class CartLazyBuilders implements TrustedCallbackInterface {

  use StringTranslationTrait;
  use IconTrait;

  /**
   * Constructs the lazy builder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected CartProviderInterface $cartProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The #lazy_builder callback; builds the cart summary.
   *
   * @param string $variation
   *   A neo_commerce settings variation id, or '' for the active settings.
   *   This is the only state the placeholder carries, and it is validated
   *   against configured variations before use -- lazy builder arguments
   *   round-trip through the client, so nothing free-form belongs here.
   *   Everything else the block renders comes from the settings behind that
   *   id, which is what lets an ajax refresh rebuild an identical block
   *   without knowing which block instance produced the markup it replaces.
   *
   * @return array
   *   A render array.
   */
  public function cartBlock(string $variation = ''): array {
    $settings = $this->resolveSettings($variation);
    $dropdown = (bool) $settings->getValue('cart_dropdown');
    $showIfEmpty = (bool) $settings->getValue('cart_show_if_empty');

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['user', 'session', 'cart']);

    $carts = $this->cartProvider->getCarts() ?: [];
    $tags = [];
    foreach ($carts as $cart) {
      // Every cart is tagged, not just the ones with items -- an empty cart
      // gaining its first item has to invalidate this too.
      $tags = Cache::mergeTags($tags, $cart->getCacheTags());
    }
    $cacheability->addCacheTags($tags);

    $carts = array_filter($carts, static function ($cart) {
      // A cart can survive in the session after checkout converted it to a
      // real order, so being a cart has to be re-checked, not assumed.
      return $cart->hasItems() && $cart->get('cart')->value;
    });

    $count = 0;
    foreach ($carts as $cart) {
      foreach ($cart->getItems() as $orderItem) {
        $count += (int) $orderItem->getQuantity();
      }
      $cacheability->addCacheableDependency($cart);
    }

    if (!$showIfEmpty && $count === 0) {
      $build = [];
      $cacheability->applyTo($build);
      return $build;
    }

    $label = (string) $settings->getValue('cart_label');
    $countText = $this->formatPlural($count, '@count item', '@count items');

    $build = [
      '#type' => 'component',
      '#component' => (string) $settings->getValue('cart_component'),
      '#props' => [
        // Echoed into a data attribute so the ajax response can find every
        // instance of this cart on the page, whatever placed it.
        'variation' => $variation,
        'count' => $count,
        'count_text' => (string) $countText,
        'label' => $label,
        // Composed here rather than wired with aria-describedby and a minted
        // id. Two carts on one page -- desktop and mobile -- would share that
        // id, and every ajax replace would mint another.
        'link_label' => (string) $this->t('@label, @items', [
          '@label' => $label,
          '@items' => $countText,
        ]),
        'url' => Url::fromRoute('commerce_cart.page')->toString(),
        'show_dropdown' => $dropdown && $carts,
      ],
      '#slots' => [
        'icon' => $this->icon($label, (string) $settings->getValue('cart_icon'))
          ->iconOnly()
          ->getRenderable(),
      ],
    ];

    if ($dropdown && $carts) {
      $build['#slots']['content'] = $this->cartViews($carts);
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Resolves a variation id to a settings object.
   *
   * @param string $variation
   *   The variation id, possibly empty or stale.
   *
   * @return \Drupal\neo_settings\Plugin\SettingsInterface
   *   The variation's settings, or the active settings when the id is empty
   *   or no longer names a variation.
   */
  protected function resolveSettings(string $variation) {
    if ($variation !== '') {
      $settings = $this->settings->get($variation);
      if ($settings) {
        return $settings;
      }
    }
    return $this->settings->getActive();
  }

  /**
   * Builds the embedded cart contents view for each cart.
   *
   * @param array $carts
   *   The non-empty carts, keyed by order id.
   *
   * @return array
   *   A render array.
   */
  protected function cartViews(array $carts): array {
    $orderTypeIds = array_map(static fn ($cart) => $cart->bundle(), $carts);
    $orderTypes = $this->entityTypeManager
      ->getStorage('commerce_order_type')
      ->loadMultiple(array_unique($orderTypeIds));

    $views = [];
    foreach ($orderTypeIds as $cartId => $orderTypeId) {
      /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $orderType */
      $orderType = $orderTypes[$orderTypeId];
      $views[] = [
        '#type' => 'view',
        '#name' => $orderType->getThirdPartySetting(
          'commerce_cart',
          'cart_block_view',
          'commerce_cart_block'
        ),
        '#arguments' => [$cartId],
        '#embed' => TRUE,
      ];
    }
    return $views;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['cartBlock'];
  }

}
