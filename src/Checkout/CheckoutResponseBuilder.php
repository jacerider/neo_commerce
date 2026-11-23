<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Checkout;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_commerce\Ajax\CheckoutSavedCommand;
use Drupal\neo_commerce\Form\AjaxFormErrors;
use Drupal\neo_commerce\Plugin\Commerce\CheckoutFlow\SinglePage;

/**
 * The server side of the single-page checkout.
 *
 * Kept out of the flow plugin because the plugin is serialized into the form
 * cache, and because these pieces test without building a checkout form.
 */
class CheckoutResponseBuilder {

  use StringTranslationTrait;

  /**
   * The drupalSettings key commerce_stripe's Payment Element script reads.
   */
  public const STRIPE_SETTINGS = 'commerceStripePaymentElement';

  /**
   * The drupalSettings key neo_commerce's checkout script reads instead.
   */
  public const NEO_STRIPE_SETTINGS = 'neoCommerceStripe';

  /**
   * Constructs the builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Drupal\neo_commerce\Checkout\CheckoutPage $page
   *   The checkout page layout, for the formatted total.
   * @param object|null $paymentOptionsBuilder
   *   Commerce Payment's options builder, NULL without commerce_payment.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    TranslationInterface $translation,
    protected CheckoutPage $page,
    protected ?object $paymentOptionsBuilder = NULL,
  ) {
    $this->setStringTranslation($translation);
  }

  /**
   * Stores the payment option the page shows on the order.
   *
   * Some panes follow the order's payment option: commerce_stripe's Payment
   * Element pane appears only once the order has a gateway, and confirms with
   * a saved card when the order has one. In the multistep flow the option is
   * stored by submitting the page before. On a single page it is stored here,
   * before the panes build: the default option on first load, then whatever
   * the shopper picks in the payment method radios.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface|null $pane
   *   The payment information pane, when the flow has one.
   * @param string|null $requested
   *   The option id the shopper picked, from the submitted input.
   *
   * @return bool
   *   TRUE when the order was changed.
   */
  public function syncPaymentOption(
    OrderInterface $order,
    ?CheckoutPaneInterface $pane,
    ?string $requested = NULL,
  ): bool {
    if (
      !$this->paymentOptionsBuilder ||
      !$pane ||
      $pane->getStepId() !== SinglePage::STEP ||
      !$order->hasField('payment_gateway') ||
      !$pane->isVisible()
    ) {
      return FALSE;
    }
    $hasGateway = !$order->get('payment_gateway')->isEmpty();
    if ($hasGateway && $requested === NULL) {
      return FALSE;
    }

    /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $storage */
    $storage = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway');
    $gateways = $storage->loadMultipleForOrder($order);
    $options = $gateways
      ? $this->paymentOptionsBuilder->buildOptions($order, $gateways)
      : [];
    if (!$options) {
      return FALSE;
    }
    if ($requested !== NULL && isset($options[$requested])) {
      $option = $options[$requested];
    }
    elseif (!$hasGateway) {
      $option = $this->paymentOptionsBuilder
        ->selectDefaultOption($order, $options);
    }
    else {
      return FALSE;
    }

    $gatewayId = $option->getPaymentGatewayId();
    $methodId = $option->getPaymentMethodId() ?: NULL;
    if (
      $order->get('payment_gateway')->target_id === $gatewayId &&
      ($order->get('payment_method')->target_id ?: NULL) == $methodId
    ) {
      return FALSE;
    }
    $order->set('payment_gateway', $gatewayId);
    $order->set('payment_method', $methodId);
    $order->save();
    return TRUE;
  }

  /**
   * Swaps commerce_stripe's Payment Element script for neo_commerce's.
   *
   * The script in commerce_stripe catches every submit of the form, stops it
   * and sends the card to Stripe, so Drupal never saves the other panes on
   * the page. On a single page those panes are the shopper's email and address.
   * Its settings are handed to neo_commerce's script under another key,
   * which saves first and then confirms with the same settings.
   *
   * @param array $form
   *   The checkout form.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order, whose balance the script compares after a save.
   */
  public function adoptStripe(array &$form, OrderInterface $order): void {
    if (!isset($form['stripe_review']['#attached'])) {
      return;
    }
    $attached = &$form['stripe_review']['#attached'];
    $libraries = $attached['library'] ?? [];
    $index = array_search('commerce_stripe/payment_element', $libraries, TRUE);
    if ($index === FALSE) {
      return;
    }
    array_splice($libraries, $index, 1, ['neo_commerce/checkout_stripe']);
    $attached['library'] = $libraries;

    if (isset($attached['drupalSettings'][self::STRIPE_SETTINGS])) {
      $attached['drupalSettings'][self::NEO_STRIPE_SETTINGS] =
        $attached['drupalSettings'][self::STRIPE_SETTINGS];
      unset($attached['drupalSettings'][self::STRIPE_SETTINGS]);
      $attached['drupalSettings'][self::NEO_STRIPE_SETTINGS]['balance'] =
        $this->balance($order);
    }
  }

  /**
   * Builds the response to the save button.
   *
   * On errors, every pane but the ones holding a live gateway iframe comes
   * back with its inline messages and ARIA wiring, plus the summary. On
   * success the summary is cleared. Either way the script hears how it went.
   *
   * @param array $form
   *   The form: as validated on errors, as rebuilt on success.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order, reloaded after the save on success.
   * @param string[] $paneIds
   *   The visible panes on the page.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function saveResponse(
    array &$form,
    FormStateInterface $formState,
    OrderInterface $order,
    array $paneIds,
  ): AjaxResponse {
    $response = new AjaxResponse();
    $summary = '#neo-checkout-error-summary';

    if (!$formState->hasAnyErrors()) {
      $form['neo_error_summary'] = array_intersect_key(
        $form['neo_error_summary'] ?? [],
        array_flip(['#type', '#weight', '#attributes'])
      );
      $response->addCommand(
        new ReplaceCommand($summary, $form['neo_error_summary'])
      );
      $this->addSummaryCommands($response, $form, $order);
      $response->addCommand(
        new CheckoutSavedCommand(TRUE, $this->balance($order))
      );
      return $response;
    }

    $errors = $formState->getErrors();
    $idMap = [];
    AjaxFormErrors::wire($form, $errors, $idMap);
    $form['neo_error_summary']['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Please complete the highlighted fields.'),
      '#attributes' => ['class' => ['form-error-summary__intro']],
      '#weight' => -10,
    ];
    $form['neo_error_summary'] += AjaxFormErrors::summary(
      $errors,
      $idMap,
      $this->getStringTranslation()
    );
    $response->addCommand(
      new ReplaceCommand($summary, $form['neo_error_summary'])
    );

    foreach ($paneIds as $paneId) {
      if (
        in_array($paneId, SinglePage::KEEP_PANES, TRUE) ||
        empty($form[$paneId]['#id'])
      ) {
        continue;
      }
      $response->addCommand(
        new ReplaceCommand('#' . $form[$paneId]['#id'], $form[$paneId])
      );
    }
    $response->addCommand(new InvokeCommand($summary, 'focus'));
    $response->addCommand(new CheckoutSavedCommand(FALSE));

    // The messages are inline in this response. Leaving them queued shows
    // them again on the next page load.
    $this->messenger->deleteByType(MessengerInterface::TYPE_ERROR);
    return $response;
  }

  /**
   * Builds the response to a pane's own ajax on the page.
   *
   * Used in place of Commerce's whole-form refresh (a coupon, a shipping rate,
   * an address recalculation) and of the payment option switch. Every pane
   * comes back on its own, so the card typed into a gateway iframe survives,
   * unless the payment option changed and the gateway pane must follow it.
   *
   * @param array $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order, as the rebuild loaded it.
   * @param string[] $paneIds
   *   The visible panes on the page.
   * @param bool $withGateway
   *   Whether to redraw the panes holding a gateway iframe too.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function refreshResponse(
    array &$form,
    FormStateInterface $formState,
    OrderInterface $order,
    array $paneIds,
    bool $withGateway = FALSE,
  ): AjaxResponse {
    $response = new AjaxResponse();
    if ($formState->hasAnyErrors()) {
      $idMap = [];
      AjaxFormErrors::wire($form, $formState->getErrors(), $idMap);
    }
    foreach ($paneIds as $paneId) {
      if (
        empty($form[$paneId]['#id']) ||
        (!$withGateway && in_array($paneId, SinglePage::KEEP_PANES, TRUE))
      ) {
        continue;
      }
      $response->addCommand(
        new ReplaceCommand('#' . $form[$paneId]['#id'], $form[$paneId])
      );
    }
    $this->addSummaryCommands($response, $form, $order);
    // Errors and notices from the pane, such as an invalid coupon, go at the
    // top of the pane the shopper was using, where they are looking, rather
    // than staying queued for the next page.
    $messages = ['#type' => 'status_messages'];
    $parents = $formState->getTriggeringElement()['#array_parents'] ?? [];
    $paneId = ($parents[0] ?? NULL) === 'sidebar'
      ? ($parents[1] ?? NULL)
      : ($parents[0] ?? NULL);
    $wrapper = ($parents[0] ?? NULL) === 'sidebar'
      ? ($form['sidebar'][$paneId]['#id'] ?? NULL)
      : ($form[$paneId]['#id'] ?? NULL);
    $response->addCommand($wrapper
      ? new PrependCommand('#' . $wrapper, $messages)
      : new HtmlCommand('#neo-checkout-messages', $messages));
    return $response;
  }

  /**
   * Redraws the sidebar panes and the total in the phone summary bar.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response.
   * @param array $form
   *   The form, as rebuilt.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addSummaryCommands(
    AjaxResponse $response,
    array &$form,
    OrderInterface $order,
  ): void {
    $sidebar = $form['sidebar'] ?? [];
    foreach (Element::children($sidebar) as $paneId) {
      if (!empty($form['sidebar'][$paneId]['#id'])) {
        $response->addCommand(new ReplaceCommand(
          '#' . $form['sidebar'][$paneId]['#id'],
          $form['sidebar'][$paneId]
        ));
      }
    }
    $total = $this->page->total($order);
    $response->addCommand(new HtmlCommand(
      '[data-neo-checkout-total]',
      ['#plain_text' => $total]
    ));
    if ($total !== '') {
      $response->addCommand(new HtmlCommand(
        '#neo-checkout-total-live',
        ['#plain_text' => $this->t('Order total: @total', ['@total' => $total])]
      ));
    }
  }

  /**
   * Returns the order balance as a decimal string.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The balance, or '' when the order has no total yet.
   */
  protected function balance(OrderInterface $order): string {
    $balance = $order->getBalance();
    // Trimmed, because a loaded order says 10.000000 where a fresh one says
    // 10, and the script compares the two as strings.
    return $balance ? Calculator::trim($balance->getNumber()) : '';
  }

}
