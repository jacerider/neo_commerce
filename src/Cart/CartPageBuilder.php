<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_cart\Plugin\views\field\EditQuantity;
use Drupal\commerce_cart\Plugin\views\field\RemoveButton;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\neo_commerce\Product\ImageDataTrait;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\views\ViewExecutable;

/**
 * Builds the cart page component for one cart.
 *
 * The cart stays Commerce's views form. Its quantity fields, remove buttons
 * and checkout button stay where the Form API built them; only the markup
 * around them changes. Views already prints form elements this way: the
 * rendered view carries a placeholder comment per element and row, and
 * ViewsFormMainForm::preRenderViewsForm() swaps each one for the rendered
 * element. This builder writes those same placeholders into the component's
 * slots, so the elements land in the design's places and every submit
 * handler, ajax callback and form alter still sees the form it expects.
 *
 * A placeholder has to reach the markup untouched. A string prop would be
 * escaped on the way through twig, so each one travels as a Markup slot --
 * which is why every line is its own `cart_line` component rather than a
 * row in an array prop.
 */
class CartPageBuilder {

  use ImageDataTrait;
  use StringTranslationTrait;

  /**
   * Constructs the builder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\neo_commerce\Cart\CartCopy $copy
   *   The cart copy resolver.
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currencyFormatter
   *   The currency formatter.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected CartCopy $copy,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected CartProviderInterface $cartProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Returns the current shopper's carts that hold something.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   The carts, keyed by order id.
   */
  public function nonEmptyCarts(): array {
    return array_filter(
      $this->cartProvider->getCarts(),
      static fn (OrderInterface $cart): bool => $cart->hasItems()
    );
  }

  /**
   * Builds one cart's page, as /cart and /cart/{order} show it.
   *
   * A cart with items is Commerce's cart form view, which the cart form
   * alter renders through the cart page component. One without is the
   * component's empty state; there is no form to build.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart.
   * @param bool $compact
   *   Whether to build the compact layout. Only the empty state reads it
   *   here: a cart with items is the cart form, which the cart form alter
   *   builds compact from the request itself.
   *
   * @return array
   *   A render array.
   */
  public function cart(OrderInterface $order, bool $compact = FALSE): array {
    if (!$order->hasItems()) {
      return $this->buildEmpty($order, $compact);
    }
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface|null $type */
    $type = $this->entityTypeManager
      ->getStorage('commerce_order_type')
      ->load($order->bundle());
    $view = $type
      ? $type->getThirdPartySetting('commerce_cart', 'cart_form_view', 'commerce_cart_form')
      : 'commerce_cart_form';
    $build = [
      '#prefix' => '<div class="cart cart-form">',
      '#suffix' => '</div>',
      '#type' => 'view',
      '#name' => $view,
      '#arguments' => [$order->id()],
      '#embed' => TRUE,
    ];
    (new CacheableMetadata())
      ->addCacheableDependency($order)
      ->addCacheContexts(['user', 'session'])
      ->applyTo($build);
    return $build;
  }

  /**
   * Builds the overview of several carts, or the empty state for none.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   The non-empty carts to show.
   * @param bool $compact
   *   Whether to build the compact layout, for the cart modal.
   *
   * @return array
   *   The cart overview component.
   */
  public function overview(array $carts, bool $compact = FALSE): array {
    $settings = $this->settings->getActive();
    $copy = $this->copy->overview();
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user', 'session'])
      ->addCacheTags(['config:neo_commerce.settings']);

    $cards = [];
    foreach ($carts as $cart) {
      $cacheability->addCacheableDependency($cart);
      $cards[] = $this->card($cart, $copy, $cacheability);
    }

    $build = [
      '#type' => 'component',
      '#component' => (string) $settings->getValue('cart_overview_component'),
      '#props' => [
        'kicker' => $copy['kicker'],
        'title' => $copy['title'],
        'title_tag' => 'h1',
        'intro' => $copy['intro'],
        'carts' => $cards,
        'empty' => $cards === [],
        'empty_title' => $copy['empty_title'],
        'empty_text' => $copy['empty_text'],
        'empty_cta_label' => $copy['empty_cta_label'],
        'empty_cta_url' => $this->href($copy['empty_cta_url'], $cacheability),
        'compact' => $compact,
      ],
      '#slots' => [],
    ];
    $explainer = $this->copy->explainer();
    if ($explainer && count($cards) > 1) {
      $build['#slots']['explainer'] = ['#markup' => $explainer];
    }
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Builds one cart's card on the overview.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart.
   * @param array $overview
   *   The overview copy.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the card's dependencies.
   *
   * @return array
   *   The card data.
   */
  protected function card(OrderInterface $cart, array $overview, CacheableMetadata $cacheability): array {
    $settings = $this->settings->getActive();
    $copy = $this->copy->forOrderType($cart->bundle());
    $limit = max(1, (int) $settings->getValue('cart_overview_thumbnail_limit'));

    $items = $cart->getItems();
    $thumbnails = [];
    foreach (array_slice($items, 0, $limit) as $item) {
      $cacheability->addCacheableDependency($item);
      $variation = $item->getPurchasedEntity();
      $sources = [$variation];
      if ($variation instanceof ProductVariationInterface) {
        $sources[] = $variation->getProduct();
      }
      $image = $this->firstImage($sources, $cacheability);
      $thumbnails[] = [
        'src' => $image['src'] ?? '',
        'alt' => $image['alt'] ?? (string) $item->label(),
        'title' => (string) $item->label(),
        'quantity' => (int) (float) $item->getQuantity(),
      ];
    }

    $count = $this->itemCount($cart);
    $subtotal = $cart->getSubtotalPrice();
    return [
      'title' => $this->copy->cardTitle($cart->bundle()),
      'count' => $count,
      'count_text' => (string) $this->formatPlural($count, '1 item', '@count items'),
      'subtotal' => $this->format($subtotal),
      'notice' => $copy['notice'],
      'notice_icon' => $copy['notice_icon'],
      'thumbnails' => $thumbnails,
      'more' => max(0, count($items) - $limit),
      'continue_label' => $copy['continue_label'],
      'continue_url' => $this->href($copy['continue_url'], $cacheability),
      'view_label' => $overview['view_label'],
      'view_url' => $this->href(
        Url::fromRoute('neo_commerce.cart_order', ['commerce_order' => $cart->id()]),
        $cacheability
      ),
    ];
  }

  /**
   * Builds the page for a cart rendered by the cart views form.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart.
   * @param \Drupal\views\ViewExecutable $view
   *   The executed cart form view.
   * @param array $placeholders
   *   The views form placeholders, keyed by form element name, then row id.
   * @param array $actions
   *   Placeholders for the form's action buttons, keyed `checkout` and
   *   `update`.
   * @param bool $compact
   *   Whether to build the compact layout, for the cart modal.
   *
   * @return array
   *   The cart page component.
   */
  public function build(OrderInterface $order, ViewExecutable $view, array $placeholders, array $actions = [], bool $compact = FALSE): array {
    $cacheability = $this->baseCacheability($order);
    [$quantityName, $removeName] = $this->formElementNames($view);

    $lines = [];
    foreach ($view->result as $rowId => $row) {
      $item = $this->orderItem($view, $row);
      if (!$item) {
        continue;
      }
      $lines[] = $this->line(
        $item,
        $this->slot($placeholders[$quantityName][$rowId] ?? NULL),
        $this->slot($placeholders[$removeName][$rowId] ?? NULL),
        $cacheability
      );
    }

    $build = $this->page($order, $cacheability, $lines === [], $compact);
    $build['#slots']['lines'] = $lines;
    $build['#slots']['summary'] = $order->get('total_price')->view([
      'label' => 'hidden',
      'type' => 'commerce_order_total_summary',
    ]);
    foreach (['checkout', 'update'] as $key) {
      if (isset($actions[$key])) {
        $build['#slots'][$key] = $this->slot($actions[$key]);
      }
    }
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Builds the page for a cart with nothing in it.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart.
   * @param bool $compact
   *   Whether to build the compact layout, for the cart modal.
   *
   * @return array
   *   The cart page component, in its empty state.
   */
  public function buildEmpty(OrderInterface $order, bool $compact = FALSE): array {
    $cacheability = $this->baseCacheability($order);
    $build = $this->page($order, $cacheability, TRUE, $compact);
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Builds the page component around whatever lines the caller adds.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the links' cacheability.
   * @param bool $empty
   *   Whether the cart has no lines.
   * @param bool $compact
   *   Whether to build the compact layout.
   *
   * @return array
   *   The component.
   */
  protected function page(OrderInterface $order, CacheableMetadata $cacheability, bool $empty, bool $compact = FALSE): array {
    $copy = $this->copy->forOrderType($order->bundle());
    $build = [
      '#type' => 'component',
      '#component' => (string) $this->settings->getActive()
        ->getValue('cart_page_component'),
      '#props' => [
        'kicker' => $copy['kicker'],
        'title' => $copy['title'],
        'title_tag' => 'h1',
        'intro' => $copy['intro'],
        'notice_icon' => $copy['notice_icon'],
        'footnote' => $copy['footnote'],
        'continue_label' => $copy['continue_label'],
        'continue_url' => $this->href($copy['continue_url'], $cacheability),
        'empty' => $empty,
        'empty_title' => $copy['empty_title'],
        'empty_text' => $copy['empty_text'],
        'empty_cta_label' => $copy['empty_cta_label'],
        'empty_cta_url' => $this->href($copy['empty_cta_url'], $cacheability),
        'item_count' => $this->itemCount($order),
        'compact' => $compact,
      ],
      '#slots' => [],
    ];
    if ($copy['notice'] !== '') {
      $build['#slots']['notice'] = ['#markup' => $copy['notice']];
    }

    // With more than one cart, /cart is the overview, so every cart page is
    // one of several: link back, and say why they check out apart.
    $cacheability->addCacheContexts(['user', 'session']);
    if (count($this->nonEmptyCarts()) > 1) {
      $build['#props']['back_label'] = $this->copy->overview()['back_label'];
      $build['#props']['back_url'] = $this->href(
        Url::fromRoute('commerce_cart.page'),
        $cacheability
      );
      $explainer = $this->copy->explainer();
      if ($explainer) {
        $build['#slots']['explainer'] = ['#markup' => $explainer];
      }
    }
    return $build;
  }

  /**
   * Returns the order's lines as plain data, for an order summary.
   *
   * The same image, name, meta line and totals as the cart lines, without
   * their controls, for the single-page checkout's summary.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the lines' cacheability.
   *
   * @return array[]
   *   One array per line: title, meta, total, count, and image (the image
   *   prop data: src, alt, width, height; NULL when there is none).
   */
  public function summaryItems(OrderInterface $order, CacheableMetadata $cacheability): array {
    $field = (string) $this->settings->getActive()
      ->getValue('product_tagline_field');
    $items = [];
    foreach ($order->getItems() as $item) {
      $cacheability->addCacheableDependency($item);
      $variation = $item->getPurchasedEntity();
      $sources = [];
      if ($variation instanceof ProductVariationInterface) {
        $cacheability->addCacheableDependency($variation);
        $sources = array_filter([$variation, $variation->getProduct()]);
      }
      $items[] = [
        'title' => (string) $item->label(),
        'meta' => $this->firstString($sources, $field),
        'total' => $this->format($item->getTotalPrice()),
        'count' => (int) (float) $item->getQuantity(),
        'image' => $this->firstImage($sources, $cacheability),
      ];
    }
    return $items;
  }

  /**
   * Returns the first image found on the sources, as image prop data.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface[] $sources
   *   The entities to read, in order: the variation, then its product.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the image's dependencies.
   *
   * @return array|null
   *   The image (src, alt, width, height), or NULL when none has one.
   */
  protected function firstImage(array $sources, CacheableMetadata $cacheability): ?array {
    $field = (string) $this->settings->getActive()
      ->getValue('product_image_field');
    foreach (array_filter($sources) as $source) {
      $image = $this->imageData($source, $field, $cacheability);
      if ($image) {
        return $image;
      }
    }
    return NULL;
  }

  /**
   * Builds one line.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   * @param array|null $quantity
   *   The quantity element's placeholder slot.
   * @param array|null $remove
   *   The remove button's placeholder slot.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the line's dependencies.
   *
   * @return array
   *   The line component.
   */
  public function line(OrderItemInterface $item, ?array $quantity, ?array $remove, CacheableMetadata $cacheability): array {
    $settings = $this->settings->getActive();
    $cacheability->addCacheableDependency($item);
    $variation = $item->getPurchasedEntity();
    $product = NULL;
    $url = '';
    if ($variation instanceof ProductVariationInterface) {
      $cacheability->addCacheableDependency($variation);
      $product = $variation->getProduct();
      if ($product) {
        $cacheability->addCacheableDependency($product);
        $url = $this->href(
          $product->toUrl('canonical', ['query' => ['v' => $variation->id()]]),
          $cacheability
        );
      }
    }
    $sources = array_filter([$variation, $product]);

    $build = [
      '#type' => 'component',
      '#component' => (string) $settings->getValue('cart_line_component'),
      '#props' => [
        'title' => (string) $item->label(),
        'url' => $url,
        'meta' => $this->firstString(
          $sources,
          (string) $settings->getValue('product_tagline_field')
        ),
        'price' => $this->format($item->getUnitPrice()),
        'total' => $this->format($item->getTotalPrice()),
        'count' => (int) (float) $item->getQuantity(),
      ],
      '#slots' => [],
    ];
    $image = $this->firstImage($sources, $cacheability);
    if ($image) {
      $build['#props']['image'] = $image;
    }
    if ($quantity) {
      $build['#slots']['quantity'] = $quantity;
    }
    if ($remove) {
      $build['#slots']['remove'] = $remove;
    }
    return $build;
  }

  /**
   * Finds the form element names of the quantity and remove fields.
   *
   * A site may rename either field in its copy of the view, so they are
   * found by handler class rather than assumed.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The cart form view.
   *
   * @return array
   *   The quantity and remove element names; either may be NULL.
   */
  protected function formElementNames(ViewExecutable $view): array {
    $quantity = $remove = NULL;
    foreach ($view->field as $id => $handler) {
      if ($handler instanceof EditQuantity) {
        $quantity = $id;
      }
      elseif ($handler instanceof RemoveButton) {
        $remove = $id;
      }
    }
    return [$quantity, $remove];
  }

  /**
   * Finds a result row's order item.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The cart form view.
   * @param \Drupal\views\ResultRow $row
   *   The row.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface|null
   *   The order item, or NULL when no field resolves one.
   */
  protected function orderItem(ViewExecutable $view, $row): ?OrderItemInterface {
    foreach ($view->field as $handler) {
      $entity = $handler->getEntity($row);
      if ($entity instanceof OrderItemInterface) {
        return $entity;
      }
    }
    return NULL;
  }

  /**
   * Reads the first non-empty string field value from a list of entities.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface[] $entities
   *   The entities, in the order to try them.
   * @param string $field
   *   The field name.
   *
   * @return string
   *   The value, or ''.
   */
  protected function firstString(array $entities, string $field): string {
    if ($field === '') {
      return '';
    }
    foreach ($entities as $entity) {
      if ($entity instanceof FieldableEntityInterface && $entity->hasField($field)) {
        $value = (string) ($entity->get($field)->value ?? '');
        if ($value !== '') {
          return $value;
        }
      }
    }
    return '';
  }

  /**
   * Counts the units in a cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart.
   *
   * @return int
   *   The total quantity.
   */
  protected function itemCount(OrderInterface $order): int {
    $count = 0;
    foreach ($order->getItems() as $item) {
      $count += (int) (float) $item->getQuantity();
    }
    return $count;
  }

  /**
   * Formats a price, or returns '' for none.
   */
  protected function format(?Price $price): string {
    return $price
      ? $this->currencyFormatter->format(
        $price->getNumber(),
        $price->getCurrencyCode()
      )
      : '';
  }

  /**
   * Turns a Url into a string, keeping its cacheability.
   */
  protected function href(?Url $url, CacheableMetadata $cacheability): string {
    if (!$url) {
      return '';
    }
    $generated = $url->toString(TRUE);
    $cacheability->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

  /**
   * Wraps a placeholder so it reaches the markup unescaped.
   *
   * A slot takes a render array, and #markup holding a Markup object is
   * printed as is, where a plain string would be filtered and lose the
   * comment.
   */
  protected function slot(?string $placeholder): ?array {
    return $placeholder === NULL
      ? NULL
      : ['#markup' => Markup::create($placeholder)];
  }

  /**
   * Starts the cacheability every build shares.
   */
  protected function baseCacheability(OrderInterface $order): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheableDependency($order)
      ->addCacheTags(['config:neo_commerce.settings']);
  }

}
