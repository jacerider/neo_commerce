<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Decides when a cart or checkout page uses the simple page layout.
 *
 * The simple layout (neo_base's page--simple.html.twig) swaps the site
 * header and footer for a bar holding the logo and one back link, so the
 * cart and checkout hold the shopper's attention. Each area has its own
 * setting, both off by default.
 */
class SimpleLayout {

  use StringTranslationTrait;

  /**
   * The cart routes the cart setting covers.
   */
  public const CART_ROUTES = ['commerce_cart.page', 'neo_commerce.cart_order'];

  /**
   * The checkout route the checkout setting covers.
   */
  public const CHECKOUT_ROUTE = 'commerce_checkout.form';

  /**
   * Constructs the service.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\neo_commerce\Cart\CartCopy $copy
   *   The cart copy resolver.
   * @param \Drupal\neo_commerce\Cart\CartPageBuilder $pageBuilder
   *   The cart page builder.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected CartCopy $copy,
    protected CartPageBuilder $pageBuilder,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Returns which area's simple layout applies to a route, if any.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return string|null
   *   'cart', 'checkout', or NULL when the page keeps the site layout.
   */
  public function area(RouteMatchInterface $routeMatch): ?string {
    $route = $routeMatch->getRouteName();
    $settings = $this->settings->getActive();
    if (in_array($route, self::CART_ROUTES, TRUE)
      && $settings->getValue('simple_layout_cart')) {
      return 'cart';
    }
    if ($route === self::CHECKOUT_ROUTE
      && $settings->getValue('simple_layout_checkout')) {
      return 'checkout';
    }
    return NULL;
  }

  /**
   * Returns the header's back link for a simple page.
   *
   * Cart pages go on shopping, using the order type's continue link. A
   * checkout step goes back to its cart, except the last one: once the
   * order is placed there is no cart to go back to, so it goes on shopping.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return array
   *   The link `label` and `url` (a Url).
   */
  public function backLink(RouteMatchInterface $routeMatch): array {
    $order = $routeMatch->getParameter('commerce_order');
    $order = $order instanceof OrderInterface ? $order : NULL;

    if ($routeMatch->getRouteName() === self::CHECKOUT_ROUTE
      && $order
      && $routeMatch->getParameter('step') !== 'complete') {
      return [
        'label' => (string) $this->t('Back to cart'),
        'url' => Url::fromRoute('neo_commerce.cart_order', [
          'commerce_order' => $order->id(),
        ]),
      ];
    }

    // /cart names no order: with one cart it is that cart's page, with
    // several it is the overview, which reads the default copy.
    if (!$order && $routeMatch->getRouteName() === 'commerce_cart.page') {
      $carts = $this->pageBuilder->nonEmptyCarts();
      $order = count($carts) === 1 ? reset($carts) : NULL;
    }
    $copy = $this->copy->forOrderType($order ? $order->bundle() : '');
    return [
      'label' => $copy['continue_label'] !== ''
        ? $copy['continue_label']
        : (string) $this->t('Continue shopping'),
      'url' => $copy['continue_url'] ?? Url::fromRoute('<front>'),
    ];
  }

}
