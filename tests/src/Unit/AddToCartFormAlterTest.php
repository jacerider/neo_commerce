<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Unit;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_commerce\Cart\AddToCartFormAlter;
use Drupal\neo_commerce\Event\AddToCartCleanKeysEvent;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the add to cart ajax wiring and its extracted form handlers.
 *
 * The whole point of the extraction is that this file needs no container:
 * every dependency arrives through the constructor, so if a test here has to
 * reach for \Drupal, the seam did not land.
 */
#[Group('neo_commerce')]
#[CoversClass(AddToCartFormAlter::class)]
class AddToCartFormAlterTest extends UnitTestCase {

  /**
   * The button gets the ajax callback and a name unique to this form.
   *
   * Commerce leaves #name unset, so every add to cart button on a listing
   * page would otherwise share `op` and the wrong form could claim a submit.
   */
  public function testWiresAjaxCallbackAndUniqueSubmitName(): void {
    $form = ['actions' => ['submit' => ['#type' => 'submit']]];
    $formState = $this->createMock(FormStateInterface::class);

    $this->alterService(['add_to_cart_message' => ''])
      ->alter($form, $formState, 'commerce_order_item_add_to_cart_form_7');

    $submit = $form['actions']['submit'];
    $wrapperId = 'commerce-order-item-add-to-cart-form-7';

    $this->assertSame($wrapperId, $form['#wrapper_id']);
    $this->assertSame($wrapperId, $submit['#name']);
    $this->assertSame(
      [AddToCartFormAlter::class, 'ajax'],
      $submit['#ajax']['callback']
    );
    $this->assertSame($wrapperId, $submit['#ajax']['wrapper']);
    $this->assertSame('click', $submit['#ajax']['event']);
    $this->assertStringContainsString(
      '<div id="' . $wrapperId . '">',
      $form['#prefix']
    );
    $this->assertContains(
      'neo_commerce/cart',
      $form['#attached']['library']
    );
  }

  /**
   * The error summary container is reserved before the response needs it.
   */
  public function testReservesErrorSummaryContainer(): void {
    $form = ['actions' => ['submit' => ['#type' => 'submit']]];
    $formState = $this->createMock(FormStateInterface::class);

    $this->alterService(['add_to_cart_error_summary' => TRUE])
      ->alter($form, $formState, 'commerce_order_item_add_to_cart_form_7');

    $attributes = $form['error_summary']['#attributes'];
    $this->assertSame('container', $form['error_summary']['#type']);
    $this->assertSame(
      'commerce-order-item-add-to-cart-form-7--error-summary',
      $attributes['id']
    );
    $this->assertSame('alert', $attributes['role']);
    $this->assertSame('assertive', $attributes['aria-live']);
    $this->assertSame('-1', $attributes['tabindex']);
  }

  /**
   * With the setting off the form is handed back exactly as it arrived.
   */
  public function testLeavesFormUntouchedWhenAjaxIsOff(): void {
    $original = ['actions' => ['submit' => ['#type' => 'submit']]];
    $form = $original;
    $formState = $this->createMock(FormStateInterface::class);

    $this->alterService(['add_to_cart_ajax' => FALSE])
      ->alter($form, $formState, 'commerce_order_item_add_to_cart_form_7');

    $this->assertSame($original, $form);
  }

  /**
   * A validation failure cancels the rebuild.
   *
   * Without this the entity form rebuilds from user input and the response
   * renders a second, differently built copy of the form.
   */
  public function testOnValidateSuppressesRebuildWhenValidationFailed(): void {
    $form = [];

    $failed = $this->formState(TRUE);
    $failed->setRebuild(TRUE);
    $this->alterService()->onValidate($form, $failed);
    $this->assertFalse($failed->isRebuilding());

    $passed = $this->formState(FALSE);
    $passed->setRebuild(TRUE);
    $this->alterService()->onValidate($form, $passed);
    $this->assertTrue($passed->isRebuilding());
  }

  /**
   * A successful submit puts a fresh order item on the form object.
   *
   * The form still holds the item that was just added, so the next submit
   * would edit that line instead of adding a second one.
   */
  public function testOnSubmitSwapsInFreshOrderItem(): void {
    $purchased = new \stdClass();
    $entity = $this->orderItem('default', $purchased);
    $fresh = $this->createMock(EntityInterface::class);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('create')
      ->with([
        'type' => 'default',
        'purchased_entity' => $purchased,
      ])
      ->willReturn($fresh);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('commerce_order_item')
      ->willReturn($storage);

    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->method('getEntity')->willReturn($entity);
    $formObject->expects($this->once())->method('setEntity')->with($fresh);

    $formState = $this->formState(FALSE);
    $formState->setFormObject($formObject);
    $formState->setUserInput(['form_id' => 'x']);

    $form = [];
    $this->alterService([], $entityTypeManager)
      ->onSubmit($form, $formState);

    // The rebuild is what renders the emptied form back to the shopper.
    $this->assertTrue($formState->isRebuilding());
  }

  /**
   * A subscriber's key survives the pruning of the posted values.
   */
  public function testOnSubmitKeepsSubscriberUserInputKey(): void {
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->once())
      ->method('dispatch')
      ->with(
        $this->isInstanceOf(AddToCartCleanKeysEvent::class),
        AddToCartCleanKeysEvent::EVENT_NAME
      )
      ->willReturnCallback(static function (object $event) {
        $event->addKey('commerce_option_refresh');
        return $event;
      });

    $formState = $this->formState(FALSE);
    $formState->setFormObject($this->entityForm());
    $formState->setUserInput([
      'form_id' => 'x',
      'quantity' => 2,
      'commerce_option_refresh' => 1,
      'ajax_page_state' => ['theme' => 'front'],
      '_triggering_element_name' => 'op',
    ]);

    $form = [];
    $this->alterService([], NULL, $dispatcher)->onSubmit($form, $formState);

    $pruned = $formState->getUserInput();
    $this->assertSame(
      [
        'form_id' => 'x',
        'commerce_option_refresh' => 1,
        'ajax_page_state' => ['theme' => 'front'],
        '_triggering_element_name' => 'op',
      ],
      $pruned
    );
    // The quantity is what the pruning exists to drop.
    $this->assertArrayNotHasKey('quantity', $pruned);
  }

  /**
   * The callables in the form array keep their class and method names.
   *
   * They are serialised into the form cache, so a rename would break every
   * form already built on a running site.
   */
  public function testKeepsStaticSubmitCallable(): void {
    $form = ['actions' => ['submit' => ['#type' => 'submit']]];
    $formState = $this->createMock(FormStateInterface::class);

    $this->alterService()
      ->alter($form, $formState, 'commerce_order_item_add_to_cart_form_7');

    $this->assertSame(
      ['Drupal\neo_commerce\Cart\AddToCartFormAlter', 'validate'],
      end($form['#validate'])
    );
    $submitHandlers = $form['actions']['submit']['#submit'];
    $this->assertSame(
      ['Drupal\neo_commerce\Cart\AddToCartFormAlter', 'submit'],
      end($submitHandlers)
    );

    foreach (['validate', 'submit'] as $name) {
      $method = new \ReflectionMethod(AddToCartFormAlter::class, $name);
      $this->assertTrue($method->isStatic(), "$name is static");
      $this->assertTrue($method->isPublic(), "$name is public");
    }
    foreach (['onValidate', 'onSubmit'] as $name) {
      $method = new \ReflectionMethod(AddToCartFormAlter::class, $name);
      $this->assertFalse($method->isStatic(), "$name is an instance method");
      $this->assertTrue($method->isPublic(), "$name is public");
    }
  }

  /**
   * Builds the service with mocked dependencies and no container.
   *
   * @param array $overrides
   *   Settings values overriding the shipped defaults.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, or NULL for an unused mock.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface|null $dispatcher
   *   The event dispatcher, or NULL for a pass-through mock.
   *
   * @return \Drupal\neo_commerce\Cart\AddToCartFormAlter
   *   The alter service.
   */
  protected function alterService(
    array $overrides = [],
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?EventDispatcherInterface $dispatcher = NULL,
  ): AddToCartFormAlter {
    if ($dispatcher === NULL) {
      $dispatcher = $this->createMock(EventDispatcherInterface::class);
      $dispatcher->method('dispatch')->willReturnArgument(0);
    }
    return new AddToCartFormAlter(
      $this->settingsRepository($overrides),
      $entityTypeManager ?? $this->entityTypeManager(),
      $dispatcher,
      $this->createMock(TranslationInterface::class),
    );
  }

  /**
   * An entity type manager whose storage creates a throwaway entity.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager double.
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('create')
      ->willReturn($this->createMock(EntityInterface::class));
    $entityTypeManager = $this->createMock(
      EntityTypeManagerInterface::class
    );
    $entityTypeManager->method('getStorage')->willReturn($storage);
    return $entityTypeManager;
  }

  /**
   * A real form state, because hasAnyErrors() is static and cannot be mocked.
   *
   * The flag it reads is global to the class, so every caller sets it rather
   * than inheriting whatever the previous test left behind.
   *
   * @param bool $hasErrors
   *   Whether validation failed.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state.
   */
  protected function formState(bool $hasErrors): FormState {
    $formState = new FormState();
    $formState->clearErrors();
    if ($hasErrors) {
      $formState->setErrorByName('quantity', 'Enter a quantity.');
    }
    return $formState;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The error flag is static, so it would leak into the next test class.
    (new FormState())->clearErrors();
    parent::tearDown();
  }

  /**
   * A settings repository double returning the shipped defaults.
   *
   * @param array $overrides
   *   Settings values overriding the shipped defaults.
   *
   * @return \Drupal\neo_settings\SettingsRepositoryInterface
   *   The repository double.
   */
  protected function settingsRepository(
    array $overrides = [],
  ): SettingsRepositoryInterface {
    $values = $overrides + [
      'add_to_cart_ajax' => TRUE,
      'add_to_cart_message' => 'Adding to cart...',
      'add_to_cart_success' => 'modal',
      'add_to_cart_error_summary' => FALSE,
      'add_to_cart_guard' => TRUE,
    ];
    $settings = $this->createMock(SettingsInterface::class);
    $settings->method('getValue')->willReturnCallback(
      static fn ($key) => $values[$key] ?? NULL
    );
    $repository = $this->createMock(SettingsRepositoryInterface::class);
    $repository->method('getActive')->willReturn($settings);
    return $repository;
  }

  /**
   * A stand-in for the order item the entity form holds.
   *
   * Only the three reads onSubmit() makes are modelled; a mock cannot carry
   * the `purchased_entity` field property the real entity exposes.
   *
   * @param string $bundle
   *   The order item bundle.
   * @param object $purchased
   *   The purchased entity field.
   *
   * @return object
   *   The order item double.
   */
  protected function orderItem(string $bundle, object $purchased): object {
    return new class($bundle, $purchased) {

      /**
       * The purchased entity field.
       *
       * @var object
       */
      // phpcs:ignore
      public object $purchased_entity;

      /**
       * The order item bundle.
       *
       * @var string
       */
      protected string $itemBundle;

      /**
       * Constructs the double.
       *
       * @param string $bundle
       *   The order item bundle.
       * @param object $purchased
       *   The purchased entity field.
       */
      public function __construct(string $bundle, object $purchased) {
        $this->itemBundle = $bundle;
        $this->purchased_entity = $purchased;
      }

      /**
       * Gets the entity type id.
       *
       * @return string
       *   The entity type id.
       */
      public function getEntityTypeId(): string {
        return 'commerce_order_item';
      }

      /**
       * Gets the bundle.
       *
       * @return string
       *   The bundle.
       */
      public function bundle(): string {
        return $this->itemBundle;
      }

    };
  }

  /**
   * An entity form whose swap is not the subject of the test.
   *
   * @return \Drupal\Core\Entity\EntityFormInterface
   *   The form object double.
   */
  protected function entityForm(): EntityFormInterface {
    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->method('getEntity')
      ->willReturn($this->orderItem('default', new \stdClass()));
    return $formObject;
  }

}
