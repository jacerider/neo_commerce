<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\neo_commerce\Event\AddToCartResponseEvent;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\views\Form\ViewsForm;
use Drupal\views\Form\ViewsFormMainForm;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the two ajax responses the cart integration funnels through.
 *
 * Kernel rather than unit: the commands are the behaviour, and they are only
 * the real commands when they are built from a real settings repository, a
 * real cart and a real rendered view. Both subjects are the forms the two
 * alter services actually produce, so a response asserted here is the one a
 * shopper's browser receives.
 */
#[Group('neo_commerce')]
class CartResponseBuilderTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'neo',
    'neo_build',
    'neo_color',
    'neo_icon',
    'neo_settings',
    'neo_modal',
    'neo_tooltip',
    'neo_alchemist',
    'neo_commerce',
    'commerce_checkout',
  ];

  /**
   * A product variation.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $variation;

  /**
   * The product the add to cart form is built for.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_commerce']);

    // The add to cart form only builds for a variation the current user may
    // view, and Commerce resolves that through the product's default
    // variation. A kernel test runs as the anonymous user, who has no
    // permissions at all, so the form would come back empty.
    $admin = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    $variationType = ProductVariationType::load('default');
    $variationType->setGenerateTitle(FALSE);
    $variationType->save();

    $this->variation = ProductVariation::create([
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'title' => 'A thing',
      'price' => new Price('9.99', 'USD'),
      'status' => 1,
    ]);
    $this->variation->save();

    $this->product = Product::create([
      'type' => 'default',
      'title' => 'A product',
      'stores' => [$this->store],
      'variations' => [$this->variation],
    ]);
    $this->product->save();

    // Commerce ships the add to cart form mode showing the variation alone.
    // The quantity field is added here because an invalid control is what
    // the error path exists to describe, and without one there is nothing
    // for the aria wiring to attach to.
    $display = EntityFormDisplay::load(
      'commerce_order_item.default.add_to_cart'
    ) ?: EntityFormDisplay::create([
      'targetEntityType' => 'commerce_order_item',
      'bundle' => 'default',
      'mode' => 'add_to_cart',
      'status' => TRUE,
    ]);
    $display->setComponent('quantity', [
      'type' => 'commerce_quantity',
      'weight' => 1,
    ]);
    $display->save();
  }

  /**
   * A failed validation replaces the wrapper with the aria-wired form.
   *
   * The whole wrapper goes back rather than a messages insert, because the
   * inline message Drupal renders beside each invalid field only exists in a
   * freshly rendered form -- and the aria attributes that point a screen
   * reader at it are attached on the way out.
   */
  public function testReplacesWrapperWithWiredFormOnError(): void {
    $form = $this->addToCartForm();
    $formState = $this->failedValidation($form);

    $response = $this->respondToAddToCart($form, $formState);
    $commands = $response->getCommands();

    $this->assertSame('insert', $commands[0]['command']);
    $this->assertSame('replaceWith', $commands[0]['method']);
    $this->assertSame(
      '#' . $form['#wrapper_id'],
      $commands[0]['selector']
    );

    $html = (string) $commands[0]['data'];
    // The wrapper itself has to come back, or the next response replaces
    // markup the browser no longer holds.
    $this->assertStringContainsString(
      '<div id="' . $form['#wrapper_id'] . '">',
      $html
    );
    // The invalid control announces itself and names its own message.
    $this->assertStringContainsString('aria-invalid="true"', $html);
    $this->assertStringContainsString(
      'aria-describedby="edit-quantity-0-value--error"',
      $html
    );
    // The summary lists the failure and links straight at the field.
    $this->assertStringContainsString(
      'href="#edit-quantity-0-value"',
      $html
    );
    $this->assertStringContainsString('Enter a quantity.', $html);
  }

  /**
   * The summary is focused, and the queued error messages are dropped.
   *
   * The messages are rendered inline in this very response. Leaving them on
   * the messenger means the shopper meets them a second time, unprompted, on
   * whatever page they load next.
   */
  public function testFocusesSummaryAndClearsQueuedMessages(): void {
    $form = $this->addToCartForm();
    $formState = $this->failedValidation($form);
    $messenger = $this->container->get('messenger');
    $this->assertNotEmpty(
      $messenger->messagesByType(MessengerInterface::TYPE_ERROR)
    );

    $response = $this->respondToAddToCart($form, $formState);
    $commands = $response->getCommands();

    $this->assertSame('invoke', $commands[1]['command']);
    $this->assertSame('focus', $commands[1]['method']);
    $this->assertSame(
      '#' . $form['#wrapper_id'] . '--error-summary',
      $commands[1]['selector']
    );
    // Ordered after the replace: the summary only exists once the markup
    // carrying it has been swapped in.
    $this->assertSame('insert', $commands[0]['command']);

    $this->assertEmpty(
      $messenger->messagesByType(MessengerInterface::TYPE_ERROR)
    );
  }

  /**
   * Success replaces the emptied form, refreshes the cart, opens the modal.
   *
   * Three commands, in that order. Dropping any one of them leaves a shopper
   * looking at a page that did not change.
   */
  public function testReplacesRefreshesAndOpensModalOnSuccess(): void {
    $form = $this->addToCartForm();
    $formState = $this->passedValidation();

    $response = $this->respondToAddToCart($form, $formState);
    $commands = $response->getCommands();

    $this->assertCount(3, $commands);
    $this->assertSame('insert', $commands[0]['command']);
    $this->assertSame(
      '#' . $form['#wrapper_id'],
      $commands[0]['selector']
    );

    // Every cart summary on the page advertises the variation it was built
    // from; the refresh aims one command at each.
    $this->assertSame('insert', $commands[1]['command']);
    $this->assertSame('[data-neo-cart=""]', $commands[1]['selector']);

    $this->assertSame('ajaxRedirect', $commands[2]['command']);
    $this->assertSame('modal', $commands[2]['data']['dialogType']);
    $this->assertSame('/cart', $commands[2]['data']['url']);
    // `neo` is the only key neo_modal_ajax_render_alter() reads.
    $this->assertSame(
      'neo_modal_shelf_top',
      $commands[2]['data']['dialog']['neo']['preset']
    );
    $this->assertSame(
      'Shopping cart',
      $commands[2]['data']['dialog']['neo']['title']
    );
  }

  /**
   * The redirect setting sends the shopper to the cart page instead.
   */
  public function testRedirectsInsteadOfModal(): void {
    $this->setSettings(['add_to_cart_success' => 'redirect']);
    $form = $this->addToCartForm();
    $formState = $this->passedValidation();

    $response = $this->respondToAddToCart($form, $formState);
    $commands = $response->getCommands();

    $this->assertCount(3, $commands);
    $this->assertSame('redirect', $commands[2]['command']);
    $this->assertSame('/cart', $commands[2]['url']);
    $this->assertSame(
      ['insert', 'insert', 'redirect'],
      array_column($commands, 'command')
    );
  }

  /**
   * Both paths dispatch the event, saying which one ran.
   *
   * A subscriber appending to the response is the documented extension
   * point, so the dispatch has to come after the builder is finished with
   * the response rather than before.
   */
  public function testDispatchesResponseEventOnBothPaths(): void {
    $seen = [];
    $this->container->get('event_dispatcher')->addListener(
      AddToCartResponseEvent::EVENT_NAME,
      static function (AddToCartResponseEvent $event) use (&$seen): void {
        $seen[] = $event->hasErrors;
        $event->response->addCommand(
          new SettingsCommand(['subscriber' => TRUE])
        );
      }
    );

    $form = $this->addToCartForm();
    $failed = $this->respondToAddToCart(
      $form,
      $this->failedValidation($form)
    );
    $this->container->get('messenger')->deleteAll();
    $passed = $this->respondToAddToCart($form, $this->passedValidation());

    $this->assertSame([TRUE, FALSE], $seen);
    // The subscriber's command is last on both, which is only true if the
    // dispatch happens once the builder has added everything of its own.
    foreach ([$failed, $passed] as $response) {
      $commands = $response->getCommands();
      $this->assertSame('settings', end($commands)['command']);
    }
  }

  /**
   * A cart form submit rebuilds that cart and announces what happened.
   */
  public function testRebuildsCartFormAndFlushesMessages(): void {
    $cart = $this->createCart([1, 2]);
    [$form, $formState] = $this->cartForm($cart);
    $wrapperId = $form['#wrapper_id'];

    // What the browser posted, and what the nested form build would find if
    // it were left in place: rendering the view below builds this same form
    // again, and one click would remove two lines.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->request->set('_triggering_element_name', 'delete-order-item-0');
    $request->request->set('_triggering_element_value', 'Remove');

    $this->container->get('messenger')->deleteAll();
    $this->container->get('messenger')->addStatus('Cart updated.');

    $response = $this->respondToCartForm($form, $formState);
    $commands = $response->getCommands();

    $this->assertNull($request->request->get('_triggering_element_name'));
    $this->assertNull($request->request->get('_triggering_element_value'));

    $this->assertSame('insert', $commands[0]['command']);
    $this->assertSame('replaceWith', $commands[0]['method']);
    $this->assertSame('#' . $wrapperId, $commands[0]['selector']);
    $html = (string) $commands[0]['data'];
    // The rebuilt markup is the cart view, wrapper and live region and all,
    // so the next submit has something to replace in turn.
    $this->assertStringContainsString(
      '<div id="' . $wrapperId . '">',
      $html
    );
    $this->assertStringContainsString(
      'id="' . $wrapperId . '--messages"',
      $html
    );
    $this->assertStringContainsString('A product', $html);
    // Both lines are back, each with the remove button the alter gave it.
    $this->assertStringContainsString('name="delete-order-item-0"', $html);
    $this->assertStringContainsString('name="delete-order-item-1"', $html);

    // The cart summary is refreshed too -- the count changed.
    $this->assertSame('insert', $commands[1]['command']);
    $this->assertSame('[data-neo-cart=""]', $commands[1]['selector']);

    // Ordered after the replace on purpose: the live region these land in is
    // part of the markup that was just swapped in.
    $this->assertSame('message', $commands[2]['command']);
    $this->assertSame('Cart updated.', (string) $commands[2]['message']);
    $this->assertSame(
      '#' . $wrapperId . '--messages',
      $commands[2]['messageWrapperQuerySelector']
    );
    $this->assertSame('status', $commands[2]['messageOptions']['type']);

    $this->assertSame([], $this->container->get('messenger')->all());
    $this->assertTrue($formState->isRedirectDisabled());
  }

  /**
   * Emptying the cart returns readable markup, not an empty table.
   *
   * There is no form left to build once the last line goes, so the wrapper
   * and its live region have to be emitted by hand around the empty page.
   */
  public function testReturnsEmptyCartMarkupWithLiveRegion(): void {
    $cart = $this->createCart([1]);
    [$form, $formState] = $this->cartForm($cart);
    $wrapperId = $form['#wrapper_id'];

    $items = $cart->getItems();
    $this->container->get('commerce_cart.cart_manager')
      ->removeOrderItem($cart, reset($items));
    $this->assertFalse($cart->hasItems());

    $this->container->get('messenger')->deleteAll();
    $response = $this->respondToCartForm($form, $formState);
    $commands = $response->getCommands();

    $this->assertSame('#' . $wrapperId, $commands[0]['selector']);
    $html = (string) $commands[0]['data'];
    $this->assertStringContainsString(
      '<div id="' . $wrapperId . '">',
      $html
    );
    $this->assertStringContainsString('Your shopping cart is empty.', $html);
    // The live region survives the emptying, or the next message announced
    // into this cart would land nowhere.
    $this->assertStringContainsString(
      'id="' . $wrapperId . '--messages"',
      $html
    );
    $this->assertStringContainsString('role="status"', $html);
    $this->assertStringContainsString('aria-live="polite"', $html);
    // No table header left standing over nothing.
    $this->assertStringNotContainsString('A product', $html);
    $this->assertStringNotContainsString('delete-order-item', $html);
  }

  /**
   * Builds the real add to cart form, altered as a site would alter it.
   *
   * Built through Commerce's own lazy builder, which is what a product page
   * calls, so the form the response is handed is the one this module's alter
   * actually produced.
   *
   * @return array
   *   The form.
   */
  protected function addToCartForm(): array {
    $form = [];
    $this->inRenderContext(function () use (&$form): void {
      $form = $this->container->get('commerce_product.lazy_builders')
        ->addToCartForm($this->product->id(), 'default', TRUE, 'en');
    });
    $this->assertNotEmpty($form['#wrapper_id'] ?? '');
    return $form;
  }

  /**
   * A form state carrying a real validation failure.
   *
   * Run through the form error handler as the Form API does, so the element
   * carries #errors and the messenger carries the queued message -- which is
   * the state the response builder is written against.
   *
   * @param array $form
   *   The form, by reference; the handler writes #errors onto it.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  protected function failedValidation(array &$form): FormStateInterface {
    $formState = new FormState();
    $formState->clearErrors();
    $formState->setErrorByName('quantity][0][value', 'Enter a quantity.');
    $this->container->get('form_error_handler')
      ->handleFormErrors($form, $formState);
    return $formState;
  }

  /**
   * A form state with no errors.
   *
   * FormStateInterface::hasAnyErrors() reads a static flag, so it is
   * cleared explicitly rather than inherited from whatever the last form
   * state left behind.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  protected function passedValidation(): FormStateInterface {
    $formState = new FormState();
    $formState->clearErrors();
    return $formState;
  }

  /**
   * Builds the real cart views form for one order, altered.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart order.
   *
   * @return array
   *   The form and its form state.
   */
  protected function cartForm(OrderInterface $order): array {
    $view = Views::getView('commerce_cart_form');
    $view->setArguments([(string) $order->id()]);
    $view->execute('default');

    $formObject = ViewsForm::create(
      $this->container,
      'commerce_cart_form',
      'default',
      [(string) $order->id()]
    );
    $formState = new FormState();
    $formState->setFormObject($formObject);
    $formState->addBuildInfo('args', [$view]);
    $mainForm = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ViewsFormMainForm::class);
    $form = $mainForm->buildForm([], $formState, $view, []);

    $service = $this->container->get('neo_commerce.cart_form');
    $this->inRenderContext(
      function () use ($service, &$form, $formState, $view): void {
        $service->alter($form, $formState, $view);
      }
    );
    return [$form, $formState];
  }

  /**
   * Runs the add to cart response inside a render context.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  protected function respondToAddToCart(array $form, FormStateInterface $formState): AjaxResponse {
    $response = NULL;
    $this->inRenderContext(
      function () use ($form, $formState, &$response): void {
        $response = $this->container->get('neo_commerce.cart_response')
          ->addToCartResponse($form, $formState);
      }
    );
    return $response;
  }

  /**
   * Runs the cart form response inside a render context.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  protected function respondToCartForm(array $form, FormStateInterface $formState): AjaxResponse {
    $response = NULL;
    $this->inRenderContext(
      function () use ($form, $formState, &$response): void {
        $response = $this->container->get('neo_commerce.cart_response')
          ->cartFormResponse($form, $formState);
      }
    );
    return $response;
  }

  /**
   * Runs a callback inside a render context.
   *
   * The icons the cart renders throw outside one. A real request supplies a
   * context because all of this happens while a page is rendering; a kernel
   * test has to open its own.
   *
   * @param callable $callback
   *   The callback.
   */
  protected function inRenderContext(callable $callback): void {
    $this->container->get('renderer')
      ->executeInRenderContext(new RenderContext(), $callback);
  }

  /**
   * Overrides the shipped settings before the repository is constructed.
   *
   * @param array $values
   *   The setting values to write.
   */
  protected function setSettings(array $values): void {
    $config = $this->config('neo_commerce.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Creates a cart holding one order item per given quantity.
   *
   * @param int[] $quantities
   *   One quantity per line to add.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The cart order.
   */
  protected function createCart(array $quantities): OrderInterface {
    $cart = $this->cartProvider->createCart('default', $this->store);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('commerce_order_item');
    foreach ($quantities as $quantity) {
      $orderItem = $storage->createFromPurchasableEntity($this->variation, [
        'quantity' => $quantity,
      ]);
      $this->cartManager->addOrderItem($cart, $orderItem, FALSE);
    }
    return $cart;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The error flag is static, so it would leak into the next test class.
    (new FormState())->clearErrors();
    parent::tearDown();
  }

}
