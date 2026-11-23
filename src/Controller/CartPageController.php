<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Controller;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_commerce\Cart\CartModal;
use Drupal\neo_commerce\Cart\CartPageBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Serves /cart and /cart/{order}.
 *
 * /cart is Commerce's route; RouteSubscriber points it here. Commerce stacks
 * every cart's form on the one page. This shows the overview instead when a
 * shopper has more than one cart, and a cart's own page when there is one.
 */
class CartPageController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\neo_commerce\Cart\CartPageBuilder $builder
   *   The cart page builder.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected CartPageBuilder $builder,
    protected CartProviderInterface $cartProvider,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('neo_commerce.cart_page_builder'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('request_stack'),
    );
  }

  /**
   * Builds /cart.
   *
   * No cart with anything in it: the overview's empty state. One: that
   * cart's page, in place. More: the overview.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    $carts = $this->builder->nonEmptyCarts();
    $compact = $this->compact();
    if (count($carts) === 1) {
      return $this->builder->cart(reset($carts), $compact);
    }
    return $this->builder->overview($carts, $compact);
  }

  /**
   * Builds /cart/{order}.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The cart.
   *
   * @return array
   *   A render array.
   */
  public function order(OrderInterface $commerce_order): array {
    return $this->builder->cart($commerce_order, $this->compact());
  }

  /**
   * Whether this request opens the cart in the modal.
   */
  protected function compact(): bool {
    return CartModal::isCompactRequest($this->requestStack->getCurrentRequest());
  }

  /**
   * Allows a cart's page only to the shopper the cart belongs to.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the order is one of the shopper's carts.
   */
  public function access(OrderInterface $commerce_order, AccountInterface $account): AccessResultInterface {
    $ids = array_map('intval', $this->cartProvider->getCartIds($account));
    return AccessResult::allowedIf(
      in_array((int) $commerce_order->id(), $ids, TRUE)
    )
      ->addCacheableDependency($commerce_order)
      ->addCacheContexts(['user', 'session']);
  }

}
