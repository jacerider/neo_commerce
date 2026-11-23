<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\Component\Utility\Html;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_commerce\Event\CartFormAlterEvent;
use Drupal\neo_icon\IconTrait;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\HttpFoundation\RequestStack;
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
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\neo_commerce\Cart\CartPageBuilder $pageBuilder
   *   The cart page builder.
   * @param \Drupal\neo_commerce\Cart\CartCopy $copy
   *   The cart copy resolver.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EventDispatcherInterface $eventDispatcher,
    TranslationInterface $stringTranslation,
    protected RequestStack $requestStack,
    protected CartPageBuilder $pageBuilder,
    protected CartCopy $copy,
    protected EntityTypeManagerInterface $entityTypeManager,
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

    $this->addDialogMessages($form);
    $this->markInstantQuantities($form, $settings, $orderId);
    $this->alterQuantities($form, $settings);
    $this->alterRemoveButtons($form, $formState, $settings, $wrapperId, $ajax);
    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
    $order = $orderId === ''
      ? NULL
      : $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    $this->alterActions($form, $settings, $wrapperId, $ajax, $order);
    if ($order) {
      $this->renderAsPage($form, $view, $order);
    }

    if ($ajax) {
      $form['#submit'][] = [static::class, 'submit'];
    }

    $event = new CartFormAlterEvent($form, $formState, $wrapperId);
    $this->eventDispatcher->dispatch($event, CartFormAlterEvent::EVENT_NAME);
    $form = $event->form;
  }

  /**
   * Renders the cart through the cart page component.
   *
   * Only the view's output is replaced. The quantity fields, remove buttons
   * and action buttons stay in the form array, and the component gets the
   * placeholders ViewsFormMainForm::preRenderViewsForm() swaps for them at
   * render time. The action buttons are added to that same list, so they
   * print inside the component (the checkout button in the summary) and are
   * marked printed before the actions container renders.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\views\ViewExecutable $view
   *   The cart form view.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart.
   */
  protected function renderAsPage(array &$form, ViewExecutable $view, OrderInterface $order): void {
    $placeholders = [];
    foreach ($form['#substitutions']['#value'] ?? [] as $substitution) {
      $placeholders[$substitution['field_name']][$substitution['row_id']] =
        $substitution['placeholder'];
    }

    $actions = [];
    foreach (['checkout' => 'checkout', 'update' => 'submit'] as $slot => $key) {
      if (!isset($form['actions'][$key])) {
        continue;
      }
      $placeholder = '<!--neo-commerce-cart-action--' . $key . '-->';
      $form['#substitutions']['#value'][] = [
        'placeholder' => $placeholder,
        'field_name' => 'actions',
        'row_id' => $key,
      ];
      $actions[$slot] = $placeholder;
    }

    // The component draws its own totals summary, so the view's header and
    // footer (Commerce's order total area) would print it twice. This is the
    // flag Views reads after the form is built to drop them.
    $form['show_view_elements']['#value'] = FALSE;

    $compact = CartModal::isCompactRequest(
      $this->requestStack->getCurrentRequest()
    );
    if ($compact) {
      $form[CartModal::COMPACT_PARAMETER] = [
        '#type' => 'hidden',
        '#value' => '1',
      ];
    }

    $weight = $form['output']['#weight'] ?? 50;
    $form['output'] = $this->pageBuilder->build(
      $order,
      $view,
      $placeholders,
      $actions,
      $compact
    );
    $form['output']['#weight'] = $weight;
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
   * Renders queued messages into the cart when it opens in a dialog.
   *
   * A dialog renders the main content alone, never the page's messages
   * region, so whatever the messenger holds would otherwise sit in the
   * session until the next full page load. That is exactly what happens
   * after an ajax add to cart that opens the cart in a modal: the "added to
   * your cart" message Commerce queued has nowhere to go, and turns up on
   * the next page the shopper visits instead.
   *
   * Only a dialog request does this. A full /cart page renders the messages
   * region itself, and the ajax cart update sends its messages as commands.
   *
   * @param array $form
   *   The form.
   */
  protected function addDialogMessages(array &$form): void {
    if (!CartModal::isDialogRequest($this->requestStack->getCurrentRequest())) {
      return;
    }
    $form['messages']['#type'] ??= 'container';
    $form['messages']['#weight'] ??= -1000;
    $form['messages']['status'] = ['#type' => 'status_messages'];
  }

  /**
   * Marks the form for the script that applies quantity changes as made.
   *
   * The script finds everything by these markers rather than by Commerce's
   * element names, which a site's copy of the cart view may change.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\neo_settings\Plugin\SettingsInterface $settings
   *   The active settings.
   * @param string $orderId
   *   The cart's order id.
   */
  protected function markInstantQuantities(array &$form, $settings, string $orderId): void {
    if (!$settings->getValue('cart_quantity_instant')
      || empty($form['edit_quantity'])
      || !isset($form['actions']['submit'])) {
      return;
    }
    $form['#attributes']['data-neo-cart-instant'] = 'true';
    // A key that survives the update: the form's id and selector both gain a
    // random suffix in an ajax response.
    $form['#attributes']['data-neo-cart-order'] = $orderId;
    $form['#attached']['library'][] = 'neo_commerce/cart';
    $form['actions']['submit']['#attributes']['class'][] = 'neo-commerce-cart-update';
    // Core refocuses the triggering element after an ajax response. Here
    // that is the hidden update button, which would then show; the script
    // returns focus to the quantity control the shopper used instead.
    $form['actions']['submit']['#attributes']['data-disable-refocus'] = 'true';
    foreach (Element::children($form['edit_quantity']) as $delta) {
      $form['edit_quantity'][$delta]['#attributes']['class'][] = 'neo-commerce-cart-quantity';
    }
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
        // Passed straight through rather than wrapped in t(): the wrapper
        // registered the literal '@label' as its translatable source string
        // and translated nothing.
        $element['#value'] = $this->icon($label, $icon)
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
   * @param \Drupal\commerce_order\Entity\OrderInterface|null $order
   *   The cart, whose order type picks the checkout label.
   */
  protected function alterActions(array &$form, $settings, string $wrapperId, bool $ajax, ?OrderInterface $order): void {
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
      // The order type's copy, which falls back to cart_checkout_label.
      $checkout = $order
        ? $this->copy->forOrderType($order->bundle())['checkout_label']
        : (string) $settings->getValue('cart_checkout_label');
      if ($checkout !== '') {
        $form['actions']['checkout']['#value'] = $checkout;
      }
    }
  }

  /**
   * Form #submit callback; keeps ajax submits on the page.
   *
   * A trampoline: the Form API serialises this callable into the form cache,
   * so it has to stay a static with no dependencies of its own. Everything it
   * would need is injected into the service it hands off to.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  public static function submit(array &$form, FormStateInterface $formState): void {
    \Drupal::service('neo_commerce.cart_form')
      ->onSubmit($form, $formState);
  }

  /**
   * Keeps ajax submits on the page.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  public function onSubmit(array &$form, FormStateInterface $formState): void {
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
