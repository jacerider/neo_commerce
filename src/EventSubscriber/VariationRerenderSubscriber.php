<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\EventSubscriber;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_commerce\Ajax\AjaxFormRequestTrait;
use Drupal\neo_commerce\Ajax\VariationReplaceCommand;
use Drupal\neo_commerce\Hook\ProductHooks;
use Drupal\neo_commerce\Product\SelectedVariation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Re-renders variation-bound components when the shopper picks a variation.
 *
 * Every component of the product's tree whose props read the selected
 * variation is marked (ProductHooks::MARKER). On Commerce's variation ajax
 * change, each outermost marked instance is rendered again for the event's
 * variation and swapped in. The swap keeps the add to cart form already on
 * the page: Commerce replaces that form itself, earlier in the same
 * response, and building it a second time here would hand the shopper a
 * form reset to the default variation.
 */
class VariationRerenderSubscriber implements EventSubscriberInterface {

  use AjaxFormRequestTrait;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\neo_commerce\Product\SelectedVariation $selectedVariation
   *   The selected variation resolver.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected SelectedVariation $selectedVariation,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected RendererInterface $renderer,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the variation field slot subscriber, whose swaps target markup
    // that a re-rendered component may be about to replace wholesale.
    return [
      ProductEvents::PRODUCT_VARIATION_AJAX_CHANGE => ['onVariationChange', -10],
    ];
  }

  /**
   * Adds a replace command for each outermost marked component instance.
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
    $request = $this->requestStack->getCurrentRequest();
    $this->selectedVariation->with($variation, function () use ($product, $response, $request) {
      $this->withoutAjaxFormRequest($request, function () use ($product, $response) {
        foreach ($this->treeItems($product) as $item) {
          $hydrated = $item->get('hydrated')->getValue();
          $this->replaceMarked($item, $hydrated[ComponentTreeStructure::ROOT_UUID] ?? [], $response);
        }
      });
    });
  }

  /**
   * Replaces the marked instances of a tree level, descending past the rest.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The tree.
   * @param array $instances
   *   One level of the hydrated tree, keyed by instance uuid.
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response receiving the commands.
   */
  protected function replaceMarked(ComponentTreeItem $item, array $instances, $response): void {
    foreach ($instances as $uuid => $entry) {
      $uuid = (string) $uuid;
      $build = $item->renderComponent($uuid);
      if ($build && $this->isMarked($build)) {
        $html = $this->renderer->renderInIsolation($build);
        $response->addAttachments($build['#attached'] ?? []);
        $response->addCommand(new VariationReplaceCommand($uuid, (string) $html));
        continue;
      }
      foreach ($entry['slots'] ?? [] as $children) {
        if (is_array($children) && $children) {
          $this->replaceMarked($item, $children, $response);
        }
      }
    }
  }

  /**
   * Whether a component build carries the variation marker.
   */
  protected function isMarked(array $build): bool {
    // PagePriority wraps the page's first component one level down.
    $candidates = [$build];
    foreach ($build as $key => $child) {
      if (is_array($child) && !str_starts_with((string) $key, '#')) {
        $candidates[] = $child;
      }
    }
    foreach ($candidates as $candidate) {
      $attributes = $candidate['#props']['attributes'] ?? NULL;
      if ($attributes instanceof Attribute && $attributes->hasAttribute(ProductHooks::MARKER)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the product's component tree items.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem[]
   *   The items.
   */
  protected function treeItems(ProductInterface $product): array {
    $items = [];
    $fields = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree')['commerce_product'] ?? [];
    foreach (array_keys($fields) as $field) {
      if ($product->hasField($field)) {
        foreach ($product->get($field) as $item) {
          if ($item instanceof ComponentTreeItem) {
            $items[] = $item;
          }
        }
      }
    }
    return $items;
  }

}
