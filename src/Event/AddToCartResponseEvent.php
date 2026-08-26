<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;

/**
 * Fired once the add to cart ajax response is otherwise complete.
 *
 * Subscribers may append commands -- a promotion notice, an analytics push, a
 * second region to refresh -- without having to replace the response builder.
 */
class AddToCartResponseEvent extends Event {

  const EVENT_NAME = 'neo_commerce.add_to_cart_response';

  /**
   * Constructs the event.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response, with the cart refresh and modal commands already added.
   * @param array $form
   *   The rebuilt add to cart form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The add to cart form state. Commerce leaves the new cart's id on it as
   *   `cart_id`, which is the supported way to reach the order.
   * @param bool $hasErrors
   *   TRUE when the submission failed validation.
   */
  public function __construct(
    public readonly AjaxResponse $response,
    public readonly array $form,
    public readonly FormStateInterface $formState,
    public readonly bool $hasErrors,
  ) {}

}
