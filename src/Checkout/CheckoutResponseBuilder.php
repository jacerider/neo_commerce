<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Checkout;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
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
   * @param object|null $paymentOptionsBuilder
   *   Commerce Payment's options builder, NULL without commerce_payment.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    TranslationInterface $translation,
    protected ?object $paymentOptionsBuilder = NULL,
  ) {
    $this->setStringTranslation($translation);
  }

  /**
   * Gives an order without a payment gateway its default one.
   *
   * Some panes only appear once the order has a gateway: commerce_stripe's
   * Payment Element pane is one. In the multistep flow the shopper picks it
   * on the page before; on a single page nobody has, so the option payment
   * information would select anyway is stored up front.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface|null $pane
   *   The payment information pane, when the flow has one.
   *
   * @return bool
   *   TRUE when a gateway was set.
   */
  public function preselectPaymentGateway(
    OrderInterface $order,
    ?CheckoutPaneInterface $pane,
  ): bool {
    if (
      !$this->paymentOptionsBuilder ||
      !$pane ||
      $pane->getStepId() !== SinglePage::STEP ||
      !$order->hasField('payment_gateway') ||
      !$order->get('payment_gateway')->isEmpty() ||
      !$pane->isVisible()
    ) {
      return FALSE;
    }

    /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $storage */
    $storage = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway');
    $gateways = $storage->loadMultipleForOrder($order);
    if (!$gateways) {
      return FALSE;
    }
    $options = $this->paymentOptionsBuilder->buildOptions($order, $gateways);
    if (!$options) {
      return FALSE;
    }
    $option = $this->paymentOptionsBuilder
      ->selectDefaultOption($order, $options);

    $order->set('payment_gateway', $option->getPaymentGatewayId());
    if ($option->getPaymentMethodId()) {
      $order->set('payment_method', $option->getPaymentMethodId());
    }
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
