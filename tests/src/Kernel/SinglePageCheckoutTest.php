<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_checkout\Entity\CheckoutFlow;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\neo_commerce\Checkout\CheckoutPage;
use Drupal\neo_commerce\Plugin\Commerce\CheckoutFlow\SinglePage;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the neo_single_page checkout flow.
 */
#[Group('neo_commerce')]
class SinglePageCheckoutTest extends CartKernelTestBase {

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
    'file',
    'image',
    'neo_image',
    'commerce_checkout',
    'commerce_payment',
    'neo_commerce',
  ];

  /**
   * The flow entity.
   *
   * @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface
   */
  protected $flow;

  /**
   * The cart.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $cart;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installConfig(['commerce_checkout', 'commerce_payment']);
    $this->installConfig(['neo_commerce']);

    PaymentGateway::create([
      'id' => 'manual',
      'label' => 'Manual',
      'plugin' => 'manual',
      'configuration' => [
        'display_label' => 'Pay later',
        'collect_billing_information' => FALSE,
      ],
    ])->save();

    $this->flow = CheckoutFlow::create([
      'id' => 'single',
      'label' => 'Single',
      'plugin' => 'neo_single_page',
      'configuration' => [],
    ]);
    $this->flow->save();

    // A generated title needs a product. The add to cart message prints it.
    $variationType = ProductVariationType::load('default');
    $variationType->setGenerateTitle(FALSE);
    $variationType->save();
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'title' => 'A thing',
      'price' => new Price('10.00', 'USD'),
      'status' => 1,
    ]);
    $variation->save();

    $anonymous = $this->container->get('entity_type.manager')
      ->getStorage('user')->load(0);
    $this->cart = $this->cartProvider->createCart(
      'default',
      $this->store,
      $anonymous
    );
    $item = $this->container->get('entity_type.manager')
      ->getStorage('commerce_order_item')
      ->createFromPurchasableEntity($variation);
    $this->cartManager->addOrderItem($this->cart, $item, FALSE);
    $this->cart->set('checkout_flow', 'single');
    $this->cart->save();
  }

  /**
   * One page, then Commerce's payment and complete steps.
   */
  public function testStepsAndPanes(): void {
    $plugin = $this->plugin();

    $this->assertSame(
      [SinglePage::STEP, 'payment', 'complete'],
      array_keys($plugin->getSteps())
    );
    $this->assertSame(
      ['contact_information', 'payment_information'],
      array_keys($plugin->getVisiblePanes(SinglePage::STEP))
    );
    $this->assertSame(
      'payment',
      $plugin->getPane('payment_process')->getStepId()
    );
    foreach (['review', 'login', 'billing_information'] as $paneId) {
      $pane = $plugin->getPane($paneId);
      if ($pane) {
        $this->assertSame('_disabled', $pane->getStepId(), $paneId);
      }
    }

    // A failed payment sends the shopper "back", which is the page itself.
    $this->assertSame(SinglePage::STEP, $plugin->getPreviousStepId('review'));
    $this->assertSame(SinglePage::STEP, $plugin->getPreviousStepId('payment'));
  }

  /**
   * A gateway is stored before the page builds, so its panes can show.
   */
  public function testGatewayIsPreselected(): void {
    $plugin = $this->plugin();
    $builder = $this->container->get('neo_commerce.checkout_response');

    $this->assertTrue($builder->preselectPaymentGateway(
      $this->cart,
      $plugin->getPane('payment_information')
    ));
    $this->assertSame(
      'manual',
      $this->reload()->get('payment_gateway')->target_id
    );

    // Never overrides a gateway already chosen.
    $this->assertFalse($builder->preselectPaymentGateway(
      $this->cart,
      $plugin->getPane('payment_information')
    ));
  }

  /**
   * Saving stores the panes and stays on the page.
   */
  public function testSaveStaysOnThePage(): void {
    $formState = $this->submit([
      'contact_information' => ['email' => 'shopper@example.com'],
      SinglePage::SAVE_BUTTON => 'Save',
    ]);

    $this->assertFalse($formState->hasAnyErrors());
    $this->assertTrue($formState->isRebuilding());
    $cart = $this->reload();
    $this->assertSame('shopper@example.com', $cart->getEmail());
    $this->assertNotSame('payment', $cart->get('checkout_step')->value);
  }

  /**
   * Saving with a bad email reports it and stores nothing.
   */
  public function testSaveReportsErrors(): void {
    $formState = $this->submit([
      'contact_information' => ['email' => 'not an email'],
      SinglePage::SAVE_BUTTON => 'Save',
    ]);

    $this->assertTrue($formState->hasAnyErrors());
    $this->assertEmpty($this->reload()->getEmail());
  }

  /**
   * Place order leaves the page for Commerce's payment step.
   */
  public function testPlaceOrderGoesToPayment(): void {
    $formState = $this->submit([
      'contact_information' => ['email' => 'shopper@example.com'],
      'op' => 'Place order',
    ]);

    $this->assertFalse($formState->hasAnyErrors());
    $cart = $this->reload();
    $this->assertSame('payment', $cart->get('checkout_step')->value);
    $this->assertSame('manual', $cart->get('payment_gateway')->target_id);
  }

  /**
   * The Stripe script is swapped for neo_commerce's, settings and all.
   */
  public function testAdoptStripe(): void {
    $form = [
      'stripe_review' => [
        '#attached' => [
          'library' => ['commerce_stripe/payment_element'],
          'drupalSettings' => [
            'commerceStripePaymentElement' => ['elementId' => 'x'],
          ],
        ],
      ],
    ];
    $this->container->get('neo_commerce.checkout_response')
      ->adoptStripe($form, $this->cart);

    $attached = $form['stripe_review']['#attached'];
    $this->assertSame(['neo_commerce/checkout_stripe'], $attached['library']);
    $this->assertArrayNotHasKey(
      'commerceStripePaymentElement',
      $attached['drupalSettings']
    );
    $this->assertSame(
      'x',
      $attached['drupalSettings']['neoCommerceStripe']['elementId']
    );
    $this->assertSame(
      '10',
      $attached['drupalSettings']['neoCommerceStripe']['balance']
    );
  }

  /**
   * The save response tells the script how it went.
   */
  public function testSaveResponseCommands(): void {
    $builder = $this->container->get('neo_commerce.checkout_response');
    $form = [
      'neo_error_summary' => ['#type' => 'container'],
      'contact_information' => [
        '#id' => 'edit-contact-information',
        'email' => ['#parents' => ['contact_information', 'email']],
      ],
      'stripe_review' => ['#id' => 'edit-stripe-review'],
    ];

    $formState = new FormState();
    $response = $this->inContext(fn () => $builder->saveResponse(
      $form,
      $formState,
      $this->cart,
      ['contact_information', 'stripe_review']
    ));
    $saved = $this->command($response->getCommands(), 'neoCheckoutSaved');
    $this->assertTrue($saved['valid']);
    $this->assertSame('10', $saved['balance']);

    $formState->setErrorByName('contact_information][email', 'Bad email.');
    $response = $this->inContext(fn () => $builder->saveResponse(
      $form,
      $formState,
      $this->cart,
      ['contact_information', 'stripe_review']
    ));
    $commands = $response->getCommands();
    $this->assertFalse($this->command($commands, 'neoCheckoutSaved')['valid']);
    $selectors = array_column($commands, 'selector');
    $this->assertContains('#edit-contact-information', $selectors);
    // The pane holding Stripe's iframe is never replaced.
    $this->assertNotContains('#edit-stripe-review', $selectors);
  }

  /**
   * Copy per order type falls back to the defaults, field by field.
   */
  public function testCopyFallback(): void {
    $this->config('neo_commerce.settings')
      ->set('checkout_copy_types', [
        'default' => ['title' => 'Shipping & Payment', 'kicker' => ''],
      ])
      ->save();
    // The settings repository caches per request; start a fresh one.
    foreach (['neo_commerce.settings', 'neo_commerce.checkout_copy'] as $id) {
      $this->container->set($id, NULL);
    }

    $copy = $this->container->get('neo_commerce.checkout_copy')
      ->forOrderType('default');
    $this->assertSame('Shipping & Payment', $copy['title']);
    $this->assertSame('Place order', $copy['place_order_label']);
    $this->assertSame('', $copy['kicker']);
  }

  /**
   * The panes move into the component's slots, the hidden values stay.
   */
  public function testPanesFillTheSlots(): void {
    $form = [
      '#theme' => ['commerce_checkout_form'],
      '#neo_checkout_page' => [
        'component' => 'neo_commerce:checkout_page',
        'panes' => [
          'contact_information',
          'payment_information',
          'stripe_review',
          'customer_comments',
        ],
        'props' => ['title' => 'Checkout'],
      ],
      'contact_information' => ['#markup' => 'contact'],
      'payment_information' => ['#markup' => 'payment'],
      'stripe_review' => ['#markup' => 'stripe'],
      'customer_comments' => ['#markup' => 'comments'],
      'sidebar' => ['#markup' => 'summary'],
      'actions' => ['#markup' => 'actions'],
      'neo_error_summary' => ['#markup' => 'errors'],
      'form_build_id' => ['#markup' => 'build id'],
    ];

    $form = CheckoutPage::preRender($form);

    $this->assertArrayNotHasKey('#theme', $form);
    $this->assertArrayHasKey('form_build_id', $form);
    $slots = $form['neo_checkout_page']['#slots'];
    $this->assertSame(['contact_information'], array_keys($slots['details']));
    $this->assertSame(
      ['payment_information', 'stripe_review'],
      array_keys($slots['payment'])
    );
    $this->assertSame(['customer_comments'], array_keys($slots['extra']));
    $this->assertSame(['sidebar'], array_keys($slots['summary']));
    $this->assertSame(['actions'], array_keys($slots['actions']));
    $this->assertSame(['neo_error_summary'], array_keys($slots['errors']));
    $this->assertArrayNotHasKey('contact_information', $form);
  }

  /**
   * Returns the flow plugin, bound to the cart.
   */
  protected function plugin(): SinglePage {
    $plugin = $this->flow->getPlugin();
    $plugin->setOrder($this->cart);
    return $plugin;
  }

  /**
   * Submits the page programmatically with the given input.
   */
  protected function submit(array $input): FormState {
    $formState = new FormState();
    $formState->addBuildInfo('args', [SinglePage::STEP]);
    $formState->setUserInput($input);
    $formState->setValues($input);
    $plugin = $this->plugin();
    $this->inContext(function () use ($plugin, $formState) {
      $this->container->get('form_builder')->submitForm($plugin, $formState);
    });
    return $formState;
  }

  /**
   * Reloads the cart from storage.
   */
  protected function reload(): OrderInterface {
    return $this->container->get('entity_type.manager')
      ->getStorage('commerce_order')
      ->loadUnchanged($this->cart->id());
  }

  /**
   * Finds a command by name.
   */
  protected function command(array $commands, string $name): array {
    foreach ($commands as $command) {
      if (($command['command'] ?? '') === $name) {
        return $command;
      }
    }
    $this->fail("No $name command.");
  }

  /**
   * Runs a callable inside a render context, as a request would.
   */
  protected function inContext(callable $callable) {
    return $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      $callable
    );
  }

}
