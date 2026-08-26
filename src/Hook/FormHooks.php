<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\commerce_cart\Form\AddToCartFormInterface;
use Drupal\Component\Utility\Tags;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\neo_commerce\Cart\AddToCartFormAlter;
use Drupal\neo_commerce\Cart\CartFormAlter;
use Drupal\views\Form\ViewsForm;
use Drupal\views\ViewExecutable;

/**
 * Form hooks for neo_commerce.
 *
 * Class based rather than procedural so the alter services arrive by
 * injection. The module this replaces reached for \Drupal::service() in every
 * hook and static callback, which is what made its form logic impossible to
 * test without a full site.
 */
class FormHooks {

  /**
   * The view tag Commerce puts on every cart form view.
   */
  const CART_FORM_TAG = 'commerce_cart_form';

  /**
   * Constructs the hook handler.
   *
   * @param \Drupal\neo_commerce\Cart\AddToCartFormAlter $addToCart
   *   The add to cart form alter.
   * @param \Drupal\neo_commerce\Cart\CartFormAlter $cartForm
   *   The cart form alter.
   */
  public function __construct(
    protected AddToCartFormAlter $addToCart,
    protected CartFormAlter $cartForm,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * Ordered after commerce_checkout deliberately. That module adds the
   * Checkout button with '#submit' => array_merge($form['#submit'], ...) --
   * a snapshot of the form's submit handlers taken while its own alter runs.
   * Anything appended to $form['#submit'] afterwards never reaches that
   * button, and anything appended before is copied onto it. Running last
   * makes which of those happens a decision rather than an accident of
   * module weight.
   */
  #[Hook('form_alter', order: new OrderAfter(modules: ['commerce_checkout']))]
  public function formAlter(array &$form, FormStateInterface $formState, string $formId): void {
    $formObject = $formState->getFormObject();

    if ($formObject instanceof AddToCartFormInterface) {
      $this->addToCart->alter($form, $formState, $formId);
      return;
    }

    if ($formObject instanceof ViewsForm) {
      if ($view = $this->cartFormView($formState)) {
        $this->cartForm->alter($form, $formState, $view);
      }
    }
  }

  /**
   * Returns the view behind a cart form, if this views form is one.
   *
   * Matched on the view's tag rather than on the
   * views_form_commerce_cart_form_default form id. The form id names one
   * specific view, but which view renders a cart is per order type via the
   * cart_form_view third-party setting -- so a site with its own cart view
   * silently lost every one of these alterations.
   *
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return \Drupal\views\ViewExecutable|null
   *   The view, or NULL when this is some other views form.
   */
  protected function cartFormView(FormStateInterface $formState): ?ViewExecutable {
    $args = $formState->getBuildInfo()['args'] ?? [];
    $view = reset($args);
    if (!$view instanceof ViewExecutable) {
      return NULL;
    }
    $tags = Tags::explode((string) $view->storage->get('tag'));
    if (!in_array(self::CART_FORM_TAG, $tags, TRUE)) {
      return NULL;
    }
    // Without the order id argument there is no cart to act on, and the
    // wrapper id could not be made unique per cart.
    if (!isset($view->argument['order_id'])) {
      return NULL;
    }
    return $view;
  }

}
