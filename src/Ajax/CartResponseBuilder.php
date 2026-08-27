<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Ajax;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\neo\Ajax\AjaxRedirectCommand;
use Drupal\neo_commerce\Cart\CartLazyBuilders;
use Drupal\neo_commerce\Cart\CartModal;
use Drupal\neo_commerce\Event\AddToCartResponseEvent;
use Drupal\neo_commerce\Form\AjaxFormErrors;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Builds the ajax responses for adding to and editing the cart.
 */
class CartResponseBuilder {

  use StringTranslationTrait;

  /**
   * The request parameter a page reports its cart summaries in.
   *
   * Array-valued, written by the client as indexed keys, because the default
   * preset's `data-neo-cart` value is the empty string and an array is what
   * keeps that distinguishable from nothing having been reported at all.
   */
  protected const REPORT_PARAMETER = 'neo_cart';

  /**
   * Constructs the response builder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings repository.
   * @param \Drupal\neo_commerce\Cart\CartLazyBuilders $cartLazyBuilders
   *   The cart summary builder.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected CartLazyBuilders $cartLazyBuilders,
    protected EventDispatcherInterface $eventDispatcher,
    protected MessengerInterface $messenger,
    protected RequestStack $requestStack,
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the response for an add to cart submission.
   *
   * @param array $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function addToCartResponse(array $form, FormStateInterface $formState): AjaxResponse {
    $response = new AjaxResponse();
    $settings = $this->settings->getActive();
    $wrapper = '#' . ($form['#wrapper_id'] ?? '');
    $hasErrors = $formState->hasAnyErrors();

    if ($hasErrors) {
      $errors = $formState->getErrors();
      $idMap = [];
      AjaxFormErrors::wire($form, $errors, $idMap);

      if (isset($form['error_summary'])) {
        $form['error_summary'] += AjaxFormErrors::summary(
          $errors,
          $idMap,
          $this->getStringTranslation()
        );
      }

      // Replacing the whole wrapper rather than injecting messages, so the
      // inline .form-item--alert-message Drupal renders next to each invalid
      // field comes back with the aria wiring attached.
      $response->addCommand(new ReplaceCommand($wrapper, $form));

      if (isset($form['error_summary'])) {
        $response->addCommand(
          new InvokeCommand($wrapper . '--error-summary', 'focus')
        );
      }

      // setErrorByName() also queued every message on the messenger. They are
      // rendered inline in this response, so drop them here or the shopper
      // meets them a second time on their next page load.
      $this->messenger->deleteByType(MessengerInterface::TYPE_ERROR);
    }
    else {
      // The submit handler swapped in a fresh order item and cleared the
      // posted values so the form comes back empty and ready for another
      // add. None of that is visible unless the markup is actually replaced.
      $response->addCommand(new ReplaceCommand($wrapper, $form));

      foreach ($this->cartRefreshCommands() as $command) {
        $response->addCommand($command);
      }
      $this->addSuccessCommand($response, $settings);
    }

    $event = new AddToCartResponseEvent($response, $form, $formState, $hasErrors);
    $this->eventDispatcher->dispatch($event, AddToCartResponseEvent::EVENT_NAME);

    return $response;
  }

  /**
   * Builds the response for a cart form submission.
   *
   * @param array $form
   *   The cart views form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function cartFormResponse(array $form, FormStateInterface $formState): AjaxResponse {
    $formState->disableRedirect();
    $response = new AjaxResponse();

    // The triggering element is still sitting in POST. Rendering the cart
    // view below builds this same form again, and the form builder would
    // recognise that trigger and run its submit handlers a second time --
    // one click removing two lines.
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $request->request->set('_triggering_element_name', NULL);
      $request->request->set('_triggering_element_value', NULL);
    }

    $wrapperId = (string) ($form['#wrapper_id'] ?? '');
    $orderId = (string) ($form['#order_id'] ?? '');

    $this->withoutAjaxFormRequest($request, function () use ($response, $wrapperId, $orderId) {
      $response->addCommand(
        new ReplaceCommand('#' . $wrapperId, $this->cartBuild($orderId, $wrapperId))
      );
    });
    foreach ($this->cartRefreshCommands() as $command) {
      $response->addCommand($command);
    }

    // Ordered after the replace on purpose: the live region these land in is
    // part of the markup that was just swapped in.
    $selector = '#' . $wrapperId . '--messages';
    foreach ($this->messenger->all() as $type => $messages) {
      foreach ($messages as $message) {
        $response->addCommand(
          new MessageCommand((string) $message, $selector, ['type' => $type], FALSE)
        );
      }
    }
    $this->messenger->deleteAll();

    return $response;
  }

  /**
   * Builds the commands that refresh the cart summaries on the page.
   *
   * A cart can be placed by block layout, by an Alchemist slot, or straight
   * from twig, and a page can carry more than one -- a desktop header and a
   * mobile one. Rather than guess at a class the theme happens to emit, each
   * summary advertises the preset it was built from as `data-neo-cart`, and
   * one replace command per value covers all of them.
   *
   * Which of them are worth building is the page's answer, not this
   * method's. The module's script collects the `data-neo-cart` values the
   * document is holding and sends them with every ajax request as the cart
   * report, so a site with four presets and one summary builds one summary
   * instead of building four and throwing three away. The report is read
   * from the current request rather than passed in, which is what lets this
   * keep the name, signature and return other modules already call.
   *
   * Reported values are matched by string equality against the same list
   * enumerated below, so a value naming no configured preset is dropped --
   * exactly what already happened to a preset deleted since the page loaded
   * -- and a crafted report can never ask for more than the configured
   * preset count. Enumerating that list and skipping what the report did not
   * name, rather than walking the report, is also what collapses a repeated
   * value and what holds the order: the default first, then the presets.
   *
   * With no report every configured preset is built, exactly as before the
   * report existed. That fallback is permanent, not a migration step: a
   * browser still holding a page from before a deploy sends nothing, and so
   * does any module calling this from a request that never ran the cart's
   * script. A selector matching nothing is a no-op, so it costs what it
   * always cost.
   *
   * The preset list is deliberately unfiltered by access. The block picker
   * offers every enabled preset, and it runs for the editor while this runs
   * for the shopper -- so filtering here dropped the commands for any preset
   * the visitor could not view, freezing that cart's count at whatever it
   * was on page load with nothing in the UI saying why. Sending the command
   * is safe because CartLazyBuilders::resolveSettings() still resolves each
   * id under access and falls back to the active settings when it cannot: an
   * unviewable preset rendered from the active settings on first load and
   * re-renders from them here, so the refresh reproduces the initial render
   * and no preset's settings reach a user who may not view them. The report
   * narrows that list to what is on the page; it never widens it.
   *
   * @return \Drupal\Core\Ajax\CommandInterface[]
   *   The replace commands.
   */
  public function cartRefreshCommands(): array {
    $configured = array_keys($this->settings->getVariations(FALSE));
    $variations = array_merge([''], $configured);
    $reported = $this->cartReport();
    $commands = [];
    foreach ($variations as $variation) {
      if ($reported !== NULL && !in_array($variation, $reported, TRUE)) {
        continue;
      }
      $commands[] = new ReplaceCommand(
        '[data-neo-cart="' . $variation . '"]',
        $this->cartLazyBuilders->cartBlock($variation)
      );
    }
    return $commands;
  }

  /**
   * Reads the cart report off the current request.
   *
   * The client writes it as indexed `neo_cart[]` keys on every ajax request
   * the page makes, so it arrives as an array of the `data-neo-cart` values
   * the document held -- on the posted parameters for the usual POST, and on
   * the query for an ajax link core sends with GET.
   *
   * Nothing is interpreted here beyond the shape. The values are matched
   * against the configured presets by the caller, which is where the bound
   * on a crafted report lives, and anything that is not an array at all is
   * treated as no report rather than as a report of one.
   *
   * An empty report is an absent one. The only way to reach it is a page
   * whose every summary has been removed from the document, where every
   * command matches nothing anyway, so falling back there costs nothing.
   *
   * @return array|null
   *   The reported values, verbatim, or NULL when nothing was reported.
   */
  protected function cartReport(): ?array {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return NULL;
    }
    $reported = $request->request->all()[static::REPORT_PARAMETER] ?? NULL;
    if ($reported === NULL) {
      $reported = $request->query->all()[static::REPORT_PARAMETER] ?? NULL;
    }
    if (!is_array($reported) || $reported === []) {
      return NULL;
    }
    return $reported;
  }

  /**
   * Adds the command that runs once an item is in the cart.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response.
   * @param \Drupal\neo_settings\Plugin\SettingsInterface $settings
   *   The active settings.
   */
  protected function addSuccessCommand(AjaxResponse $response, $settings): void {
    $url = Url::fromRoute('commerce_cart.page');

    switch ($settings->getValue('add_to_cart_success')) {
      case 'modal':
        $redirect = new AjaxRedirectCommand($url);
        // `neo` is the only key neo_modal_ajax_render_alter() reads. The
        // module this replaces sent `exoModal`, named after a framework that
        // is no longer installed, so every option was discarded and the cart
        // opened with the plain dialog preset.
        //
        // Shared with the cart summary link, so clicking the cart and adding
        // to it open the same modal.
        $response->addCommand($redirect->setAsModal([
          'neo' => CartModal::options($settings),
        ]));
        break;

      case 'redirect':
        $response->addCommand(new RedirectCommand($url->toString()));
        break;
    }
  }

  /**
   * Runs a callback with the ajax-form request markers off the query.
   *
   * The replacement markup is the cart view, and rendering that view builds
   * the very form whose ajax callback we are inside. FormBuilder::buildForm()
   * sees `ajax_form` still on the query, matches the form id, and throws a
   * FormAjaxException to hand off to the ajax form subsystem -- from inside
   * the ajax form subsystem, which is a 500. `_wrapper_format` comes off for
   * the same reason: the nested build must render as an ordinary page
   * fragment, not as another ajax envelope.
   *
   * Both are restored afterwards so nothing downstream sees a request that
   * has quietly stopped describing itself as an ajax submission.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, if there is one.
   * @param callable $callback
   *   The callback to run.
   */
  protected function withoutAjaxFormRequest($request, callable $callback): void {
    if (!$request) {
      $callback();
      return;
    }
    $query = $request->query;
    $ajaxForm = $query->get(FormBuilderInterface::AJAX_FORM_REQUEST);
    $wrapperFormat = $query->get(MainContentViewSubscriber::WRAPPER_FORMAT);
    $query->remove(FormBuilderInterface::AJAX_FORM_REQUEST);
    $query->remove(MainContentViewSubscriber::WRAPPER_FORMAT);
    try {
      $callback();
    }
    finally {
      if ($ajaxForm !== NULL) {
        $query->set(FormBuilderInterface::AJAX_FORM_REQUEST, $ajaxForm);
      }
      if ($wrapperFormat !== NULL) {
        $query->set(MainContentViewSubscriber::WRAPPER_FORMAT, $wrapperFormat);
      }
    }
  }

  /**
   * Builds the replacement markup for one cart's form.
   *
   * Resolves the view the same way CartController does -- per order type, via
   * the cart_form_view third-party setting -- rather than calling the
   * controller, which renders every cart the shopper has and would duplicate
   * the others into this one's wrapper.
   *
   * @param string $orderId
   *   The order id.
   * @param string $wrapperId
   *   The wrapper id the response replaces.
   *
   * @return array
   *   A render array.
   */
  protected function cartBuild(string $orderId, string $wrapperId): array {
    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
    $order = $orderId === ''
      ? NULL
      : $this->entityTypeManager->getStorage('commerce_order')->load($orderId);

    if (!$order || !$order->hasItems()) {
      // Removing the last line empties the cart, and an empty cart form is a
      // header row with nothing under it. The wrapper and its live region are
      // rebuilt by hand here because there is no form left to build them.
      return [
        '#prefix' => '<div id="' . $wrapperId . '">',
        '#suffix' => '</div>',
        'messages' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => $wrapperId . '--messages',
            'class' => ['neo-commerce-cart-messages'],
            'role' => 'status',
            'aria-live' => 'polite',
            'aria-atomic' => 'true',
          ],
        ],
        'empty' => ['#theme' => 'commerce_cart_empty_page'],
      ];
    }

    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $orderType */
    $orderType = $this->entityTypeManager
      ->getStorage('commerce_order_type')
      ->load($order->bundle());

    // The rendered view builds this form again, which runs the cart form
    // alter again, which puts the same wrapper id back on the markup.
    return [
      '#type' => 'view',
      '#name' => $orderType->getThirdPartySetting(
        'commerce_cart',
        'cart_form_view',
        'commerce_cart_form'
      ),
      '#arguments' => [$orderId],
      '#embed' => TRUE,
    ];
  }

}
