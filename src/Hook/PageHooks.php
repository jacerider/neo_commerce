<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_commerce\Cart\SimpleLayout;

/**
 * Puts the cart and checkout on the simple page layout when it is enabled.
 */
class PageHooks {

  /**
   * Constructs the hook handler.
   *
   * @param \Drupal\neo_commerce\Cart\SimpleLayout $simpleLayout
   *   The simple layout service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(
    protected SimpleLayout $simpleLayout,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_theme_suggestions_page_alter().
   *
   * `page__simple` is neo_base's page--simple.html.twig; the area suggestion
   * after it lets a theme style the cart and checkout bars apart.
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions, array $variables): void {
    $area = $this->simpleLayout->area($this->routeMatch);
    if ($area) {
      $suggestions[] = 'page__simple';
      $suggestions[] = 'page__simple__' . $area;
    }
  }

  /**
   * Implements hook_preprocess_page().
   *
   * Runs before the theme's own preprocess, which reads `simple_layout` to
   * add the logo.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    if (!$this->simpleLayout->area($this->routeMatch)) {
      return;
    }
    $link = $this->simpleLayout->backLink($this->routeMatch);
    $variables['simple_layout'] = TRUE;
    $variables['simple_back_label'] = $link['label'];
    $variables['simple_back_url'] = $link['url']->toString();
  }

  /**
   * Implements hook_page_attachments().
   *
   * The layout follows the settings, so a page using it must be invalidated
   * when they change. The cart page already depends on them; checkout does
   * not.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $routes = array_merge(
      SimpleLayout::CART_ROUTES,
      [SimpleLayout::CHECKOUT_ROUTE]
    );
    if (in_array($this->routeMatch->getRouteName(), $routes, TRUE)) {
      $attachments['#cache']['tags'][] = 'config:neo_commerce.settings';
    }
  }

}
