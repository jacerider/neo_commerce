<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Checkout;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\neo_commerce\Cart\CartPageBuilder;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Lays the single-page checkout out in the `checkout_page` component.
 *
 * The panes stay where Commerce builds them, in the form tree, so every pane
 * validates, submits and ajax-refreshes as it always did. Only at render time
 * does a pre_render callback lift them into the component's slots. A pane
 * replaced over ajax renders on its own, without this callback, and keeps
 * its own wrapper id, so it lands back in the same slot.
 */
class CheckoutPage implements TrustedCallbackInterface {

  /**
   * Panes per slot. Any other pane on the page goes to `extra`.
   */
  public const SLOTS = [
    'details' => ['neo_contact', 'contact_information', 'login'],
    'shipping' => ['shipping_information'],
    'payment' => ['payment_information', 'stripe_review'],
  ];

  /**
   * Constructs the layout builder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\neo_commerce\Checkout\CheckoutCopy $copy
   *   The checkout copy resolver.
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currencyFormatter
   *   The currency formatter.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $elementInfo
   *   The element info manager.
   * @param \Drupal\neo_commerce\Cart\CartPageBuilder $cartPageBuilder
   *   The cart page builder, for the summary lines.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected CheckoutCopy $copy,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected ElementInfoManagerInterface $elementInfo,
    protected CartPageBuilder $cartPageBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender', 'preRenderPane'];
  }

  /**
   * Prepares the checkout form for the component.
   *
   * @param array $form
   *   The checkout form, as the flow built it.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string[] $paneIds
   *   The visible panes on the page, in order.
   */
  public function decorate(
    array &$form,
    OrderInterface $order,
    array $paneIds,
  ): void {
    $copy = $this->copy->forOrderType($order->bundle());
    if ($copy['place_order_label'] !== '' && isset($form['actions']['next'])) {
      $form['actions']['next']['#value'] = $copy['place_order_label'];
    }

    $total = $this->total($order);
    $form['#neo_checkout_page'] = [
      'component' => (string) $this->settings->getActive()
        ->getValue('checkout_page_component'),
      'panes' => $paneIds,
      'props' => [
        'kicker' => $copy['kicker'],
        'title' => $copy['title'],
        'title_tag' => 'h1',
        'intro' => $copy['intro'],
        'summary_title' => $copy['summary_title'],
        'summary_toggle_label' => $copy['summary_toggle_label'],
        'secure_note' => $copy['secure_note'],
        'total' => $total,
      ],
    ];
    if (isset($form['sidebar']['order_summary']['summary'])) {
      $cacheability = new CacheableMetadata();
      $form['sidebar']['order_summary']['summary'] = [
        '#theme' => 'neo_commerce_checkout_summary',
        '#items' => $this->cartPageBuilder->summaryItems($order, $cacheability),
        '#totals' => $order->get('total_price')->view([
          'label' => 'hidden',
          'type' => 'commerce_order_total_summary',
        ]),
      ];
      $cacheability->applyTo($form['sidebar']['order_summary']['summary']);
    }

    $form['#pre_render'][] = [static::class, 'preRender'];
    foreach ($paneIds as $paneId) {
      if (isset($form[$paneId]['#type'])) {
        // Set before the form merges the element type's defaults, which it
        // does with +=, so the type's own callbacks have to be kept here.
        $form[$paneId]['#pre_render'] = array_merge(
          $this->elementInfo->getInfoProperty(
            $form[$paneId]['#type'],
            '#pre_render',
            []
          ),
          $form[$paneId]['#pre_render'] ?? [],
          [[static::class, 'preRenderPane']]
        );
      }
    }
  }

  /**
   * Returns the order total, formatted.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The total, or '' when the order has none yet.
   */
  public function total(OrderInterface $order): string {
    $price = $order->getTotalPrice();
    return $price
      ? $this->currencyFormatter->format(
        $price->getNumber(),
        $price->getCurrencyCode()
      )
      : '';
  }

  /**
   * Moves the panes into the component's slots.
   *
   * @param array $element
   *   The checkout form.
   *
   * @return array
   *   The form, its visible parts now inside one component element.
   */
  public static function preRender(array $element): array {
    $page = $element['#neo_checkout_page'] ?? NULL;
    if (!$page || empty($page['component'])) {
      return $element;
    }

    $slots = [];
    foreach ($page['panes'] as $paneId) {
      if (!isset($element[$paneId])) {
        continue;
      }
      $slots[static::slotFor($paneId)][$paneId] = $element[$paneId];
      unset($element[$paneId]);
    }
    $moves = [
      'neo_total_live' => 'errors',
      'neo_messages' => 'errors',
      'neo_error_summary' => 'errors',
      'sidebar' => 'summary',
      'actions' => 'actions',
    ];
    foreach ($moves as $key => $slot) {
      if (isset($element[$key])) {
        $slots[$slot][$key] = $element[$key];
        unset($element[$key]);
      }
    }

    // The component takes over from Commerce's two-column template. The
    // hidden build id, token and form id stay behind as the form's own
    // children, so the form still posts.
    unset($element['#theme']);
    $element['neo_checkout_page'] = [
      '#type' => 'component',
      '#component' => $page['component'],
      '#props' => $page['props'],
      '#slots' => $slots,
      '#weight' => -100,
    ];
    return $element;
  }

  /**
   * Prepares one pane's markup for the page.
   *
   * Runs whenever the pane renders, on the page and on its own in an ajax
   * response, so what it adds comes back with every redraw.
   *
   * - Every set of radios with a choice to make (saved addresses, shipping
   *   rates, payment options) is marked `data-neo-radio-cards`, for the
   *   theme to draw as cards and the page script to fold to the chosen one.
   * - Commerce Shipping's pane is split into its two parts, the address and
   *   the method, so they can sit in separate cards as in the design. The
   *   method's heading is the rate field's own label.
   *
   * @param array $element
   *   The pane.
   *
   * @return array
   *   The pane.
   */
  public static function preRenderPane(array $element): array {
    static::markRadioCards($element);

    if (isset($element['shipping_profile'], $element['shipments'])) {
      $element['neo_shipping_address'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-checkout-shipping__address']],
        '#weight' => -100,
        'shipping_profile' => $element['shipping_profile'],
      ];
      $element['neo_shipping_method'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-checkout-shipping__method']],
        '#weight' => 100,
        'shipments' => $element['shipments'],
      ];
      if (isset($element['recalculate_shipping'])) {
        $element['neo_shipping_method']['recalculate_shipping'] =
          $element['recalculate_shipping'];
        unset($element['recalculate_shipping']);
      }
      unset($element['shipping_profile'], $element['shipments']);
      $element['#attributes']['class'][] = 'neo-checkout-shipping';
      $element['#sorted'] = FALSE;
    }
    return $element;
  }

  /**
   * Marks every radios element offering more than one option.
   *
   * @param array $element
   *   The element to walk.
   */
  protected static function markRadioCards(array &$element): void {
    if (
      ($element['#type'] ?? NULL) === 'radios' &&
      count($element['#options'] ?? []) > 1
    ) {
      $element['#attributes']['data-neo-radio-cards'] = '';
    }
    foreach (Element::children($element) as $key) {
      if (is_array($element[$key])) {
        static::markRadioCards($element[$key]);
      }
    }
  }

  /**
   * Returns the slot a pane belongs in.
   *
   * @param string $paneId
   *   The pane id.
   *
   * @return string
   *   The slot name.
   */
  public static function slotFor(string $paneId): string {
    foreach (self::SLOTS as $slot => $paneIds) {
      if (in_array($paneId, $paneIds, TRUE)) {
        return $slot;
      }
    }
    return 'extra';
  }

}
