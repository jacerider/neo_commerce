<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\neo_commerce\Cart\CartFormAlter;
use Drupal\neo_commerce\Event\CartFormAlterEvent;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use Drupal\views\Form\ViewsForm;
use Drupal\views\Form\ViewsFormMainForm;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart views form alter.
 *
 * Kernel rather than unit: the remove-button relabel renders an icon through
 * the icon repository and the renderer, and the subject is a real cart views
 * form built by Commerce's own field handlers from a real order. Mocking
 * either out would leave the test asserting its own mocks.
 */
#[Group('neo_commerce')]
class CartFormAlterTest extends CartKernelTestBase {

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
    // Installed so the Checkout button under test is the real one Commerce
    // adds, rather than a hand-written stand-in for it.
    'commerce_checkout',
  ];

  /**
   * A product variation.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $variation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_commerce']);

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
  }

  /**
   * Number quantity fields become plus/minus; a locked line does not.
   *
   * RemoveButton and EditQuantity disagree about locked items -- one skips
   * them, the other renders a read-only #type item -- so the deltas do not
   * line up and the swap has to dispatch on the element, not the index.
   */
  public function testQuantityPlusMinus(): void {
    $order = $this->createCart([1, 2]);
    $this->lockOrderItem($order, 1);
    [$form, $formState, $view] = $this->buildCartForm($order);

    $this->applyAlter($form, $formState, $view);

    $types = [];
    foreach ($view->result as $index => $row) {
      $item = $view->field['edit_quantity']->getEntity($row);
      $key = $item->isLocked() ? 'locked' : 'open';
      $types[$key] = $form['edit_quantity'][$index]['#type'];
      if ($key === 'open') {
        // Commerce's zero minimum is a remove-this-line affordance for
        // keyboard users, and raising it here would take that away.
        $this->assertSame(0, $form['edit_quantity'][$index]['#min']);
      }
    }

    $this->assertSame('number_plus_minus', $types['open']);
    $this->assertSame('item', $types['locked']);
  }

  /**
   * The remove button is relabelled through the icon builder, and ajaxified.
   *
   * The relabel goes through neo_icon rather than being assigned, which is
   * why #value comes back as rendered markup instead of the plain label the
   * no-icon branch would assign. Everything Commerce's own submit handler
   * dispatches on has to survive untouched.
   */
  public function testRemoveButtonRelabel(): void {
    $this->setSettings(['cart_remove_label' => 'Take it out']);
    $order = $this->createCart([1, 2]);
    $this->lockOrderItem($order, 1);
    [$form, $formState, $view] = $this->buildCartForm($order);

    $this->applyAlter($form, $formState, $view);

    // Commerce skips locked lines entirely, so there is one button for two
    // order items and its delta is a row index, not a sequence.
    $rows = Element::children($form['remove_button']);
    $this->assertCount(1, $rows);
    $row = reset($rows);
    $button = $form['remove_button'][$row];

    $this->assertInstanceOf(Markup::class, $button['#value']);
    $this->assertSame('Take it out', (string) $button['#value']);
    $this->assertContains(
      'neo-commerce-cart-remove',
      $button['#attributes']['class']
    );

    // Commerce's dispatch keys.
    $this->assertContains('delete-order-item', $button['#attributes']['class']);
    $this->assertSame('delete-order-item-' . $row, $button['#name']);
    $this->assertTrue($button['#remove_order_item']);
    $this->assertSame($row, $button['#row_index']);

    $this->assertSame(
      [CartFormAlter::class, 'ajax'],
      $button['#ajax']['callback']
    );
    $this->assertSame($form['#wrapper_id'], $button['#ajax']['wrapper']);
    $this->assertSame(['type' => 'none'], $button['#ajax']['progress']);
    $this->assertSame('Removing...', (string) $button['#disabled_on_click']);
  }

  /**
   * The update button takes the configured label and an ajax callback.
   *
   * EditQuantity already labels this button "Update cart", so the test
   * configures something else -- otherwise a relabel that never ran would
   * still assert green.
   */
  public function testUpdateButton(): void {
    $this->setSettings(['cart_update_label' => 'Refresh bag']);
    $order = $this->createCart([1]);
    [$form, $formState, $view] = $this->buildCartForm($order);

    $this->applyAlter($form, $formState, $view);

    $submit = $form['actions']['submit'];
    // #type submit renders from #value; the module this replaces set #title
    // instead, so its relabelling never reached the page.
    $this->assertSame('Refresh bag', (string) $submit['#value']);
    $this->assertSame(
      [CartFormAlter::class, 'ajax'],
      $submit['#ajax']['callback']
    );
    $this->assertSame($form['#wrapper_id'], $submit['#ajax']['wrapper']);
    $this->assertSame(['type' => 'none'], $submit['#ajax']['progress']);
    $this->assertSame('Updating...', (string) $submit['#disabled_on_click']);
    // Commerce's own quantity save still dispatches on this.
    $this->assertTrue($submit['#update_cart']);
  }

  /**
   * Checkout is relabelled but stays a full page submit.
   *
   * Leaving the page for the checkout flow is the point of pressing it, so
   * an ajax callback here would strand the shopper on the cart.
   */
  public function testCheckoutButton(): void {
    $this->setSettings(['cart_checkout_label' => 'Pay now']);
    $order = $this->createCart([1]);
    [$form, $formState, $view] = $this->buildCartForm($order);
    $this->addCheckoutButton($form, $formState);

    $this->applyAlter($form, $formState, $view);

    $checkout = $form['actions']['checkout'];
    $this->assertSame('Pay now', (string) $checkout['#value']);
    $this->assertArrayNotHasKey('#ajax', $checkout);
    $this->assertArrayNotHasKey('#disabled_on_click', $checkout);
    // What the submit handler tells Checkout apart by.
    $this->assertEquals($order->id(), $checkout['#order_id']);
  }

  /**
   * Every submit but Checkout is kept on the page.
   *
   * The trampoline is what the form array carries, so it stays named and
   * unchanged; the behaviour is asserted on the instance method it hands to.
   */
  public function testDisablesRedirectExceptCheckout(): void {
    $order = $this->createCart([1]);
    [$form, $formState, $view] = $this->buildCartForm($order);
    $this->addCheckoutButton($form, $formState);

    $this->applyAlter($form, $formState, $view);

    $this->assertContains([CartFormAlter::class, 'submit'], $form['#submit']);

    $service = $this->alterService();
    $rows = Element::children($form['remove_button']);

    $update = new FormState();
    $update->setTriggeringElement($form['actions']['submit']);
    $service->onSubmit($form, $update);
    $this->assertTrue($update->isRedirectDisabled());

    $remove = new FormState();
    $remove->setTriggeringElement($form['remove_button'][reset($rows)]);
    $service->onSubmit($form, $remove);
    $this->assertTrue($remove->isRedirectDisabled());

    $checkout = new FormState();
    $checkout->setTriggeringElement($form['actions']['checkout']);
    $service->onSubmit($form, $checkout);
    // Left untouched rather than set: FormState reports an unset flag as
    // NULL, and Checkout supplies its own redirect.
    $this->assertNotTrue($checkout->isRedirectDisabled());
  }

  /**
   * A CartFormAlterEvent subscriber gets the last word on the form.
   *
   * The event exists because a plain hook_form_alter() runs at an
   * unpredictable point relative to this module. If the dispatch moved above
   * any of the alterations, subscribing would stop being a guarantee.
   */
  public function testAlterEventLastWord(): void {
    $this->container->get('event_dispatcher')->addListener(
      CartFormAlterEvent::EVENT_NAME,
      function (CartFormAlterEvent $event): void {
        $event->form['actions']['submit']['#value'] = 'Subscriber wins';
        $event->form['seen'] = [
          '#wrapper_id' => $event->wrapperId,
          '#form_state' => $event->formState,
        ];
      }
    );
    $order = $this->createCart([1]);
    [$form, $formState, $view] = $this->buildCartForm($order);

    $this->applyAlter($form, $formState, $view);

    $this->assertSame(
      'Subscriber wins',
      (string) $form['actions']['submit']['#value']
    );
    // The subscriber is handed the id the module's own callbacks replace,
    // and the live form state, not copies of either.
    $this->assertSame($form['#wrapper_id'], $form['seen']['#wrapper_id']);
    $this->assertSame($formState, $form['seen']['#form_state']);
  }

  /**
   * Each cart gets its own wrapper id.
   *
   * /cart renders one form per cart when a shopper has carts in more than
   * one store. A single shared id made every one of those forms replace the
   * first.
   */
  public function testWrapperIdPerOrder(): void {
    $first = $this->createCart([1]);
    $second = $this->createCart([1], $this->createStore('Other', 'o@ex.com'));

    [$formA, $stateA, $viewA] = $this->buildCartForm($first);
    $this->applyAlter($formA, $stateA, $viewA);
    [$formB, $stateB, $viewB] = $this->buildCartForm($second);
    $this->applyAlter($formB, $stateB, $viewB);

    $expected = CartFormAlter::wrapperId((string) $first->id());
    $this->assertSame($expected, $formA['#wrapper_id']);
    $this->assertSame(
      CartFormAlter::wrapperId((string) $second->id()),
      $formB['#wrapper_id']
    );
    $this->assertNotSame($formA['#wrapper_id'], $formB['#wrapper_id']);

    // The id has to reach the markup the ajax response replaces, and the
    // live region each response writes its messages into.
    $this->assertStringContainsString(
      '<div id="' . $formA['#wrapper_id'] . '">',
      $formA['#prefix']
    );
    $this->assertSame(
      $formA['#wrapper_id'] . '--messages',
      $formA['messages']['#attributes']['id']
    );
    $this->assertSame((string) $first->id(), $formA['#order_id']);
  }

  /**
   * Runs the alter, inside a render context.
   *
   * The remove-button relabel renders an icon, and Renderer::render() throws
   * outside a context. A real request supplies one because the form is built
   * while the page is rendering; a kernel test has to open its own.
   *
   * @param array $form
   *   The form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param \Drupal\views\ViewExecutable $view
   *   The executed cart form view.
   */
  protected function applyAlter(array &$form, FormStateInterface $formState, ViewExecutable $view): void {
    $service = $this->alterService();
    $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      function () use ($service, &$form, $formState, $view): void {
        $service->alter($form, $formState, $view);
      }
    );
  }

  /**
   * Returns the alter service under test.
   *
   * Fetched lazily so a test may write settings first: the repository builds
   * its core settings instance in its own constructor.
   *
   * @return \Drupal\neo_commerce\Cart\CartFormAlter
   *   The alter service.
   */
  protected function alterService(): CartFormAlter {
    return $this->container->get('neo_commerce.cart_form');
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
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store to create the cart in. Defaults to the test store.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The cart order.
   */
  protected function createCart(array $quantities, $store = NULL): OrderInterface {
    $cart = $this->cartProvider->createCart('default', $store ?: $this->store);
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
   * Locks one of a cart's order items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart order.
   * @param int $delta
   *   The order item delta to lock.
   */
  protected function lockOrderItem(OrderInterface $order, int $delta): void {
    $items = $order->getItems();
    $items[$delta]->lock();
    $items[$delta]->save();
  }

  /**
   * Lets commerce_checkout add its Checkout button, as it does on a site.
   *
   * This module's form_alter is ordered after commerce_checkout's, so the
   * button is already there by the time the alter under test runs.
   *
   * @param array $form
   *   The cart form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  protected function addCheckoutButton(array &$form, FormStateInterface $formState): void {
    $this->container
      ->get('Drupal\commerce_checkout\Hook\CommerceCheckoutHooks')
      ->formAlter($form, $formState);
  }

  /**
   * Builds the real cart views form for one order.
   *
   * Built through the views form's own step class rather than the form
   * builder, so the form arrives exactly as Commerce's field handlers left
   * it and the alter under test is the only thing that has touched it.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cart order.
   *
   * @return array
   *   The form, the form state and the executed view.
   */
  protected function buildCartForm(OrderInterface $order): array {
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
    // What FormBuilder::prepareForm() puts there before any form_alter runs.
    // commerce_checkout copies it onto its Checkout button.
    $form['#submit'] = ['::submitForm'];

    return [$form, $formState, $view];
  }

}
