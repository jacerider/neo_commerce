<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Points Commerce's /cart at neo_commerce's cart page controller.
 *
 * The route keeps its name, path, title and access, so every link to
 * commerce_cart.page (the cart summary, the cart modal, checkout's back
 * link) still lands here.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('commerce_cart.page');
    if ($route) {
      $route->setDefault(
        '_controller',
        '\Drupal\neo_commerce\Controller\CartPageController::page'
      );
    }
  }

}
