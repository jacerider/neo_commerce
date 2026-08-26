<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_commerce\Event\CartFormAlterEvent;
use Drupal\neo_icon\IconTrait;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\views\ViewExecutable;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns the Commerce cart views form into an ajax form.
 */
class CartFormAlter {

  use StringTranslationTrait;
  use IconTrait;

  /**
   * Constructs the alter service.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EventDispatcherInterface $eventDispatcher,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Alters the cart form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param \Drupal\views\ViewExecutable $view
   *   The cart form view.
   */
  public function alter(array &$form, FormStateInterface $formState, ViewExecutable $view): void {
    $settings = $this->settings->getActive();
    $orderId = (string) $view->argument['order_id']->getValue();
    $wrapperId = static::wrapperId($orderId);
    $ajax = (bool) $settings->getValue('cart_form_ajax');

    if ($ajax) {
      $form['#wrapper_id'] = $wrapperId;
      $form['#order_id'] = $orderId;
      // Composed, not assigned: EditQuantity::viewsForm() sets #attached
      // wholesale, and nothing guarantees we are the last writer here.
      $form['#prefix'] = ($form['#prefix'] ?? '') . '<div id="' . $wrapperId . '">';
      $form['#suffix'] = '</div>' . ($form['#suffix'] ?? '');
      $form['#attached']['library'][] = 'core/drupal.ajax';
      $form['#attached']['library'][] = 'neo_commerce/cart';

      // Messages come back as MessageCommand insertions rather than a page
      // reload, so this region has to announce them itself. Neo ships no
      // announcer of its own, which is why the live region is declared here.
      $form['messages'] = [
        '#type' => 'container',
        '#weight' => -1000,
        '#attributes' => [
          'id' => $wrapperId . '--messages',
          'class' => ['neo-commerce-cart-messages'],
          'role' => 'status',
          'aria-live' => 'polite',
          'aria-atomic' => 'true',
        ],
      ];
    }

    $this->alterQuantities($form, $settings);
    $this->alterRemoveButtons($form, $formState, $settings, $wrapperId, $ajax);
    $this->alterActions($form, $settings, $wrapperId, $ajax);

    if ($ajax) {
      $form['#submit'][] = [static::class, 'submit'];
    }

    $event = new CartFormAlterEvent($form, $formState, $wrapperId);
    $this->eventDispatcher->dispatch($event, CartFormAlterEvent::EVENT_NAME);
    $form = $event->form;
  }

  /**
   * The wrapper id for one cart's form.
   *
   * Derived from the order id rather than fixed, because /cart renders one
   * form per cart when a shopper has carts in more than one store. A single
   * shared id made every one of those forms replace the first.
   *
   * @param string $orderId
   *   The order id.
   *
   * @return string
   *   The wrapper id.
   */
  public static function wrapperId(string $orderId): string {
    return Html::getId('neo-commerce-cart-' . $orderId);
  }

  /**
   * Swaps the quantity fields for plus/minus controls.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\neo_settings\Plugin\SettingsInterface $settings
   *   The active settings.
   */
  protected function alterQuantities(array &$form, $settings): void {
    if (!$settings->getValue('cart_quantity_plus_minus')) {
      return;
    }
    if (empty($form['edit_quantity'])) {
      return;
    }
    foreach (Element::children($form['edit_quantity']) as $delta) {
      // A locked order item renders as #type item, not a number field, and
      // must stay read-only.
      if (($form['edit_quantity'][$delta]['#type'] ?? NULL) !== 'number') {
        continue;
      }
      // #min stays whatever Commerce set -- zero, which it treats as a
      // request to remove the line. Raising it here would quietly take that
      // away from anyone using the keyboard.
      $form['edit_quantity'][$delta]['#type'] = 'number_plus_minus';
    }
  }

  /**
   * Restyles the remove buttons and gives them an ajax handler.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param \Drupal\neo_settings\Plugin\SettingsInterface $settings
   *   The active settings.
   * @param string $wrapperId
   *   The form wrapper id.
   * @param bool $ajax
   *   Whether ajax is enabled.
   */
  protected function alterRemoveButtons(array &$form, FormStateInterface $formState, $settings, string $wrapperId, bool $ajax): void {
    if (empty($form['remove_button'])) {
      return;
    }
    $label = (string) $settings->getValue('cart_remove_label');
    $icon = (string) $settings->getValue('cart_remove_icon');

    // Children are sparse: RemoveButton::viewsForm() skips locked order
    // items entirely, so the deltas do not line up with edit_quantity's.
    foreach (Element::children($form['remove_button']) as $delta) {
      $element = &$form['remove_button'][$delta];

      // #name, the delete-order-item class, #remove_order_item and
      // #row_index all come from Commerce and are what its submit handler
      // dispatches on. Only the presentation changes here.
      if ($icon !== '') {
        $element['#value'] = $this->icon($this->t('@label', ['@label' => $label]), $icon)
          ->iconOnly()
          ->render();
      }
      elseif ($label !== '') {
        $element['#value'] = $label;
      }
      $element['#attributes']['class'][] = 'neo-commerce-cart-remove';

      if ($ajax) {
        $element['#disabled_on_click'] = $this->t('Removing...');
        $element['#ajax'] = [
          'callback' => [static::class, 'ajax'],
          'wrapper' => $wrapperId,
          'progress' => ['type' => 'none'],
        ];
      }
    }
  }

  /**
   * Relabels the action buttons and gives update an ajax handler.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\neo_settings\Plugin\SettingsInterface $settings
   *   The active settings.
   * @param string $wrapperId
   *   The form wrapper id.
   * @param bool $ajax
   *   Whether ajax is enabled.
   */
  protected function alterActions(array &$form, $settings, string $wrapperId, bool $ajax): void {
    // Both of these are #type submit, whose label renders from #value. The
    // module this replaces set #title instead, so neither relabelling ever
    // reached the page and both buttons kept Commerce's wording.
    if (isset($form['actions']['submit'])) {
      $update = (string) $settings->getValue('cart_update_label');
      if ($update !== '') {
        $form['actions']['submit']['#value'] = $update;
      }
      if ($ajax) {
        $form['actions']['submit']['#disabled_on_click'] = $this->t('Updating...');
        $form['actions']['submit']['#ajax'] = [
          'callback' => [static::class, 'ajax'],
          'wrapper' => $wrapperId,
          'progress' => ['type' => 'none'],
        ];
      }
    }

    // Present only when commerce_checkout is installed and the cart has
    // items. We run after its form_alter, so it is safe to read here rather
    // than from a #pre_render as the old module had to.
    if (isset($form['actions']['checkout'])) {
      $checkout = (string) $settings->getValue('cart_checkout_label');
      if ($checkout !== '') {
        $form['actions']['checkout']['#value'] = $checkout;
      }
    }
  }

  /**
   * Form #submit callback; keeps ajax submits on the page.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  public static function submit(array &$form, FormStateInterface $formState): void {
    $trigger = $formState->getTriggeringElement();
    // The Checkout button carries #order_id and sets its own redirect to the
    // checkout flow. That redirect is the point of pressing it, so it is the
    // one submit here that must be allowed through.
    if (empty($trigger['#order_id'])) {
      $formState->disableRedirect();
    }
  }

  /**
   * Ajax callback.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public static function ajax(array &$form, FormStateInterface $formState) {
    return \Drupal::service('neo_commerce.cart_response')
      ->cartFormResponse($form, $formState);
  }

}
