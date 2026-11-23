<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\EventSubscriber;

use Drupal\neo_commerce\Checkout\CheckoutResponseBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Leaves the saving of a card to the shopper on the single-page checkout.
 *
 * A reusable Stripe gateway asks to keep every new card it charges, and the
 * Payment Element then tells the shopper their card may be charged again
 * later. On the single page that is a choice: "Save this card", unticked,
 * for signed-in shoppers. The intent starts without the request, and the
 * save adds it when the box is ticked.
 *
 * The event is named by string so this module does not need commerce_stripe
 * installed to load.
 */
class CheckoutStripeSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\neo_commerce\Checkout\CheckoutResponseBuilder $responseBuilder
   *   The checkout response builder.
   */
  public function __construct(
    protected CheckoutResponseBuilder $responseBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_stripe.payment_intent.create' => 'onIntentCreate',
    ];
  }

  /**
   * Drops the request to keep the card unless the shopper asked for it.
   *
   * @param object $event
   *   The commerce_stripe PaymentIntentCreateEvent.
   */
  public function onIntentCreate(object $event): void {
    if (!method_exists($event, 'getOrder')) {
      return;
    }
    $order = $event->getOrder();
    $attributes = $event->getIntentAttributes();
    if (
      !$this->responseBuilder->isSinglePage($order) ||
      !empty($attributes['payment_method']) ||
      !array_key_exists('setup_future_usage', $attributes) ||
      $order->getData(CheckoutResponseBuilder::SAVE_CARD)
    ) {
      return;
    }
    unset($attributes['setup_future_usage']);
    $event->setIntentAttributes($attributes);
  }

}
