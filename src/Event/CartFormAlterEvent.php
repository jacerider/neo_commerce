<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Form\FormStateInterface;

/**
 * Fired after neo_commerce has finished altering the cart form.
 *
 * A plain hook_form_alter() runs at an unpredictable point relative to this
 * module, so a site wanting the last word on the cart form had no reliable
 * seam. Subscribing here guarantees it.
 *
 * $form is a mutable copy rather than a reference -- PHP cannot promote a
 * by-reference constructor parameter to a property -- so the caller assigns
 * it back after dispatch. Subscribers just mutate $event->form.
 */
class CartFormAlterEvent extends Event {

  const EVENT_NAME = 'neo_commerce.cart_form_alter';

  /**
   * Constructs the event.
   *
   * @param array $form
   *   The cart views form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param string $wrapperId
   *   The id neo_commerce wrapped the form in, and the target its own ajax
   *   callbacks replace.
   */
  public function __construct(
    public array $form,
    public readonly FormStateInterface $formState,
    public readonly string $wrapperId,
  ) {}

}
