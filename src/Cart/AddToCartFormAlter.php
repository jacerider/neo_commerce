<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_commerce\Event\AddToCartCleanKeysEvent;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns Commerce's add to cart form into an ajax submit.
 */
class AddToCartFormAlter {

  use StringTranslationTrait;

  /**
   * Constructs the alter service.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Alters the add to cart form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param string $formId
   *   The form id.
   */
  public function alter(array &$form, FormStateInterface $formState, string $formId): void {
    $settings = $this->settings->getActive();
    if (!$settings->getValue('add_to_cart_ajax')) {
      return;
    }
    if (!isset($form['actions']['submit'])) {
      return;
    }

    $wrapperId = $this->wrap($form, $formId);
    $success = $settings->getValue('add_to_cart_success');

    // The modal is opened by an openDialog command coming back from a second
    // request, so its handler has to already be on the page. neo_modal
    // rewrites core/drupal.dialog.ajax to point at its own implementation, so
    // asking for the core library is asking for the neo one.
    if ($success === 'modal') {
      $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    }
    $form['#attached']['library'][] = 'neo_commerce/cart';
    $form['#attached']['drupalSettings']['neoCommerce']['guard'] =
      (bool) $settings->getValue('add_to_cart_guard');

    if ($settings->getValue('add_to_cart_error_summary')) {
      // Reserved up front so the ajax response has somewhere to write into.
      // role="alert" plus aria-live make a screen reader announce the errors
      // when they are inserted; tabindex="-1" lets the response move focus
      // here, which a page reload would otherwise have done for free.
      $form['error_summary'] = [
        '#type' => 'container',
        '#weight' => -1100,
        '#attributes' => [
          'id' => $wrapperId . '--error-summary',
          'class' => ['form-error-summary'],
          'role' => 'alert',
          'aria-live' => 'assertive',
          'aria-atomic' => 'true',
          'tabindex' => '-1',
        ],
      ];
    }

    $submit = &$form['actions']['submit'];

    // Commerce leaves #name unset, so core falls back to `op` and identifies
    // the triggering element by its value. On a listing page every add to
    // cart button then shares one name and one value, and the wrong form can
    // claim the submit. The wrapper id is already unique and stable.
    $submit['#name'] = $wrapperId;

    $message = (string) $settings->getValue('add_to_cart_message');
    if ($message !== '') {
      // neo's pre-render turns this into the neo-disable class, the
      // data-neo-disable-message attribute and the neo/disable library
      // together. Writing the attribute by hand -- as the module this
      // replaces did, using an attribute name from a framework that is no
      // longer installed -- attaches nothing and does nothing.
      $submit['#disabled_on_click'] = $message;
    }

    $submit['#ajax'] = [
      'callback' => [static::class, 'ajax'],
      'wrapper' => $wrapperId,
      'event' => 'click',
    ];
    if ($message !== '') {
      // The button's own disabled state is the progress indicator; a throbber
      // next to it as well just reads as two spinners.
      $submit['#ajax']['progress'] = ['type' => 'none'];
    }

    $form['#validate'][] = [static::class, 'validate'];
    $submit['#submit'][] = [static::class, 'submit'];
  }

  /**
   * Gives the form a stable wrapper the ajax response can replace.
   *
   * The id must survive the rebuild unchanged, because the response targets
   * the id it finds on the rebuilt form while the browser still holds the
   * original markup. Html::getUniqueId() would append a counter on the second
   * build and quietly aim the replace at nothing.
   *
   * @param array $form
   *   The form.
   * @param string $formId
   *   The form id, already unique per purchasable entity.
   *
   * @return string
   *   The wrapper id.
   */
  protected function wrap(array &$form, string $formId): string {
    if (!empty($form['#wrapper_id'])) {
      return $form['#wrapper_id'];
    }
    $wrapperId = Html::getId($formId);
    $form['#wrapper_id'] = $wrapperId;
    // Composed rather than assigned: another module may already have wrapped
    // the form for its own reasons, and clobbering its markup would break it.
    $form['#prefix'] = ($form['#prefix'] ?? '') . '<div id="' . $wrapperId . '">';
    $form['#suffix'] = '</div>' . ($form['#suffix'] ?? '');
    return $wrapperId;
  }

  /**
   * Form #validate callback.
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
  public static function validate(array &$form, FormStateInterface $formState): void {
    \Drupal::service('neo_commerce.add_to_cart')
      ->onValidate($form, $formState);
  }

  /**
   * Cancels the rebuild after a failed validation.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  public function onValidate(array &$form, FormStateInterface $formState): void {
    if ($formState->hasAnyErrors()) {
      // Without this the entity form rebuilds from user input and the ajax
      // response would render a second, differently built copy of the form.
      $formState->setRebuild(FALSE);
    }
  }

  /**
   * Submit callback; empties the form so the shopper can add another.
   *
   * A trampoline, for the reason given on ::validate().
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  public static function submit(array &$form, FormStateInterface $formState): void {
    \Drupal::service('neo_commerce.add_to_cart')
      ->onSubmit($form, $formState);
  }

  /**
   * Empties the submitted form so the shopper can add another.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  public function onSubmit(array &$form, FormStateInterface $formState): void {
    if ($formState->hasAnyErrors()) {
      return;
    }

    // This is an entity form, so it holds the order item that was just added.
    // Swap in a fresh one or the next submit would edit the line already in
    // the cart instead of adding a second.
    /** @var \Drupal\Core\Entity\EntityFormInterface $formObject */
    $formObject = $formState->getFormObject();
    /** @var \Drupal\commerce_order\Entity\OrderItem $entity */
    $entity = $formObject->getEntity();
    $storage = $this->entityTypeManager
      ->getStorage($entity->getEntityTypeId());
    $formObject->setEntity($storage->create([
      'type' => $entity->bundle(),
      'purchased_entity' => $entity->purchased_entity,
    ]));

    // Clear the posted values too, otherwise the rebuilt form is repopulated
    // from user input and still shows the quantity that was just added.
    $keys = $formState->getCleanValueKeys();
    $keys[] = 'ajax_page_state';
    $event = new AddToCartCleanKeysEvent($keys, $formState);
    $this->eventDispatcher
      ->dispatch($event, AddToCartCleanKeysEvent::EVENT_NAME);
    $keys = $event->getKeys();

    $input = $formState->getUserInput();
    foreach (array_keys($input) as $key) {
      if (!in_array($key, $keys, TRUE) && !str_starts_with((string) $key, '_')) {
        unset($input[$key]);
      }
    }
    $formState->setUserInput($input);
    $formState->setRebuild(TRUE);
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
      ->addToCartResponse($form, $formState);
  }

}
