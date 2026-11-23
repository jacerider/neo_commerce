<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Checkout;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
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
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected CheckoutCopy $copy,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
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
    $form['#pre_render'][] = [static::class, 'preRender'];
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
