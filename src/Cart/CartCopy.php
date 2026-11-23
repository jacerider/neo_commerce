<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Resolves the copy the cart page and the cart overview print.
 *
 * Every site sets its cart copy once (`cart_copy`), and may override any of
 * it per Commerce order type (`cart_copy_types`), because a shopper can hold
 * one cart per order type and each can need its own words: a scheduled
 * catering cart and a ship-now market cart say different things. An empty
 * per-type value means "use the default", so the fallback lives here and
 * nowhere else.
 *
 * Values come back ready to print. Text that allows emphasis is filtered and
 * marked safe; links come back as Url objects, so the caller turns them into
 * strings inside its own render context and keeps their cacheability.
 */
class CartCopy {

  /**
   * The keys a cart page prints, in the order the settings form shows them.
   */
  public const KEYS = [
    'kicker',
    'title',
    'intro',
    'notice',
    'notice_icon',
    'footnote',
    'checkout_label',
    'continue_label',
    'continue_url',
    'empty_title',
    'empty_text',
    'empty_cta_label',
    'empty_cta_url',
  ];

  /**
   * The keys the cart overview prints.
   */
  public const OVERVIEW_KEYS = [
    'kicker',
    'title',
    'intro',
    'empty_title',
    'empty_text',
    'empty_cta_label',
    'empty_cta_url',
  ];

  /**
   * The keys holding a link.
   */
  public const URL_KEYS = ['continue_url', 'empty_cta_url'];

  /**
   * The keys allowing emphasis and links.
   */
  public const RICH_KEYS = ['notice'];

  /**
   * The tags a rich value may carry.
   */
  public const ALLOWED_TAGS = ['strong', 'em', 'a', 'br'];

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the copy for one order type's cart page.
   *
   * @param string $orderTypeId
   *   The order type id.
   *
   * @return array
   *   Every key in self::KEYS, with the per-type value or the default.
   *   Rich keys are Markup (or '' when empty), link keys are Url objects
   *   (or NULL when no link is set).
   */
  public function forOrderType(string $orderTypeId): array {
    $settings = $this->settings->getActive();
    $defaults = (array) $settings->getValue('cart_copy');
    $types = (array) $settings->getValue('cart_copy_types');
    $overrides = (array) ($types[$orderTypeId] ?? []);

    $copy = [];
    foreach (self::KEYS as $key) {
      $value = (string) ($overrides[$key] ?? '');
      if ($value === '') {
        $value = (string) ($defaults[$key] ?? '');
      }
      $copy[$key] = $value;
    }

    if ($copy['checkout_label'] === '') {
      $copy['checkout_label'] = (string) $settings->getValue(
        'cart_checkout_label'
      );
    }
    if ($copy['title'] === '') {
      $copy['title'] = $this->orderTypeLabel($orderTypeId);
    }

    return $this->prepare($copy);
  }

  /**
   * Returns the title of one order type's card on the overview.
   *
   * The overview shows several carts at once, so the site-wide default title
   * ("Your cart") would name every card the same. Only an order type's own
   * title stands in for its label.
   *
   * @param string $orderTypeId
   *   The order type id.
   *
   * @return string
   *   The title.
   */
  public function cardTitle(string $orderTypeId): string {
    $types = (array) $this->settings->getActive()->getValue('cart_copy_types');
    $title = (string) ($types[$orderTypeId]['title'] ?? '');
    return $title !== '' ? $title : $this->orderTypeLabel($orderTypeId);
  }

  /**
   * Returns the overview copy.
   *
   * @return array
   *   Every key in self::OVERVIEW_KEYS. The link key is a Url or NULL.
   */
  public function overview(): array {
    $values = (array) $this->settings->getActive()->getValue('cart_overview');
    $copy = [];
    foreach (self::OVERVIEW_KEYS as $key) {
      $copy[$key] = (string) ($values[$key] ?? '');
    }
    return $this->prepare($copy);
  }

  /**
   * Returns the explainer shown when a shopper holds more than one cart.
   *
   * @return \Drupal\Component\Render\MarkupInterface|null
   *   The filtered explainer, or NULL when none is set.
   */
  public function explainer() {
    $text = (string) $this->settings->getActive()->getValue('cart_explainer');
    return $text === '' ? NULL : static::filter($text);
  }

  /**
   * Turns a stored link into a Url.
   *
   * Accepts what the settings form accepts: `<front>`, a path on this site
   * starting with `/`, `?` or `#`, or an absolute http(s) URL.
   *
   * @param string $value
   *   The stored value.
   *
   * @return \Drupal\Core\Url|null
   *   The Url, or NULL when the value is empty or not a link this accepts.
   */
  public static function url(string $value): ?Url {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    if ($value === '<front>') {
      return Url::fromRoute('<front>');
    }
    if (in_array($value[0], ['/', '?', '#'], TRUE)) {
      return Url::fromUserInput($value);
    }
    if (UrlHelper::isExternal($value) && UrlHelper::isValid($value, TRUE)) {
      $scheme = parse_url($value, PHP_URL_SCHEME);
      return in_array($scheme, ['http', 'https'], TRUE)
        ? Url::fromUri($value)
        : NULL;
    }
    return NULL;
  }

  /**
   * Filters text that may carry emphasis and links.
   *
   * @param string $text
   *   The stored text.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The filtered text, safe to print.
   */
  public static function filter(string $text) {
    return Markup::create(Xss::filter($text, self::ALLOWED_TAGS));
  }

  /**
   * Converts link and rich keys to their printable form.
   *
   * @param array $copy
   *   The raw copy.
   *
   * @return array
   *   The prepared copy.
   */
  protected function prepare(array $copy): array {
    foreach (self::URL_KEYS as $key) {
      if (array_key_exists($key, $copy)) {
        $copy[$key] = static::url($copy[$key]);
      }
    }
    foreach (self::RICH_KEYS as $key) {
      if (array_key_exists($key, $copy) && $copy[$key] !== '') {
        $copy[$key] = static::filter($copy[$key]);
      }
    }
    return $copy;
  }

  /**
   * Returns an order type's label, or its id when it no longer exists.
   *
   * @param string $orderTypeId
   *   The order type id.
   *
   * @return string
   *   The label.
   */
  protected function orderTypeLabel(string $orderTypeId): string {
    $type = $this->entityTypeManager
      ->getStorage('commerce_order_type')
      ->load($orderTypeId);
    return $type ? (string) $type->label() : $orderTypeId;
  }

}
