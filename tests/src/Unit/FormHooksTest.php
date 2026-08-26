<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Unit;

use Drupal\commerce_cart\Form\AddToCartFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_commerce\Cart\AddToCartFormAlter;
use Drupal\neo_commerce\Cart\CartFormAlter;
use Drupal\neo_commerce\Hook\FormHooks;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Form\ViewsForm;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which form goes to which alter service.
 *
 * The hook is pure dispatch, so every assertion here is about which service
 * was handed the form and with what -- never about what the alter did. The
 * cart form is recognised by the view's tag rather than by a form id, so a
 * site whose order type points cart_form_view at its own view keeps these
 * alterations; the negative cases are the four ways a views form is not a
 * cart form.
 */
#[Group('neo_commerce')]
#[CoversClass(FormHooks::class)]
class FormHooksTest extends UnitTestCase {

  /**
   * The add to cart alter.
   *
   * @var \Drupal\neo_commerce\Cart\AddToCartFormAlter&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $addToCart;

  /**
   * The cart form alter.
   *
   * @var \Drupal\neo_commerce\Cart\CartFormAlter&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cartForm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->addToCart = $this->createMock(AddToCartFormAlter::class);
    $this->cartForm = $this->createMock(CartFormAlter::class);
  }

  /**
   * An add to cart form reaches the add to cart alter, with its form id.
   */
  public function testRoutesAddToCartFormToAddToCartAlter(): void {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getFormObject')
      ->willReturn($this->createMock(AddToCartFormInterface::class));

    $this->addToCart->expects($this->once())
      ->method('alter')
      ->with(
        $this->anything(),
        $this->identicalTo($formState),
        $this->identicalTo('commerce_order_item_add_to_cart_form_7')
      );
    $this->cartForm->expects($this->never())->method('alter');

    $form = [];
    $this->hooks()->formAlter(
      $form,
      $formState,
      'commerce_order_item_add_to_cart_form_7'
    );
  }

  /**
   * A cart-tagged views form reaches the cart alter, with its view.
   */
  public function testRoutesCartTaggedViewsFormToCartFormAlter(): void {
    $view = $this->view('commerce_cart_form', TRUE);
    $formState = $this->viewsFormState($view);

    $this->cartForm->expects($this->once())
      ->method('alter')
      ->with(
        $this->anything(),
        $this->identicalTo($formState),
        $this->identicalTo($view)
      );
    $this->addToCart->expects($this->never())->method('alter');

    $form = [];
    $this->hooks()->formAlter(
      $form,
      $formState,
      'views_form_commerce_cart_form_default'
    );
  }

  /**
   * A views form whose view carries no cart tag is left alone.
   */
  public function testIgnoresViewsFormWithoutCartFormTag(): void {
    $formState = $this->viewsFormState($this->view('frontpage', TRUE));

    $this->cartForm->expects($this->never())->method('alter');
    $this->addToCart->expects($this->never())->method('alter');

    $form = [];
    $this->hooks()->formAlter($form, $formState, 'views_form_frontpage_page_1');
  }

  /**
   * A cart-tagged view with no order id argument is left alone.
   *
   * Without it there is no cart to act on, and no way to make the ajax
   * wrapper id unique per cart.
   */
  public function testIgnoresCartViewWithoutOrderIdArgument(): void {
    $formState = $this->viewsFormState(
      $this->view('commerce_cart_form', FALSE)
    );

    $this->cartForm->expects($this->never())->method('alter');
    $this->addToCart->expects($this->never())->method('alter');

    $form = [];
    $this->hooks()->formAlter(
      $form,
      $formState,
      'views_form_commerce_cart_form_default'
    );
  }

  /**
   * A views form whose first build argument is not a view is left alone.
   */
  public function testIgnoresNonViewFirstBuildArgument(): void {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getFormObject')
      ->willReturn($this->createMock(ViewsForm::class));
    $formState->method('getBuildInfo')
      ->willReturn(['args' => ['commerce_cart_form', 'default']]);

    $this->cartForm->expects($this->never())->method('alter');
    $this->addToCart->expects($this->never())->method('alter');

    $form = [];
    $this->hooks()->formAlter(
      $form,
      $formState,
      'views_form_commerce_cart_form_default'
    );
  }

  /**
   * The cart form tag is matched inside a multi-value tag string.
   *
   * A site is free to add its own tags to the cart view, and the tag field
   * is a comma separated list.
   */
  public function testMatchesCartFormTagInsideMultiTagString(): void {
    $view = $this->view('commerce, commerce_cart_form, custom', TRUE);
    $formState = $this->viewsFormState($view);

    $this->cartForm->expects($this->once())
      ->method('alter')
      ->with(
        $this->anything(),
        $this->identicalTo($formState),
        $this->identicalTo($view)
      );

    $form = [];
    $this->hooks()->formAlter(
      $form,
      $formState,
      'views_form_commerce_cart_form_default'
    );
  }

  /**
   * The hook handler under test.
   *
   * @return \Drupal\neo_commerce\Hook\FormHooks
   *   The hook handler.
   */
  protected function hooks(): FormHooks {
    return new FormHooks($this->addToCart, $this->cartForm);
  }

  /**
   * A views form state whose first build argument is the given view.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view the form was built from.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  protected function viewsFormState(ViewExecutable $view): FormStateInterface {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getFormObject')
      ->willReturn($this->createMock(ViewsForm::class));
    $formState->method('getBuildInfo')
      ->willReturn(['args' => [$view, 'default']]);
    return $formState;
  }

  /**
   * A view carrying the given tag string.
   *
   * @param string $tag
   *   The view's tag field, which is a comma separated list.
   * @param bool $orderId
   *   Whether the view has an order id argument handler.
   *
   * @return \Drupal\views\ViewExecutable
   *   The view.
   */
  protected function view(string $tag, bool $orderId): ViewExecutable {
    $storage = $this->createMock(ViewEntityInterface::class);
    $storage->method('get')->willReturnMap([['tag', $tag]]);

    $view = $this->createMock(ViewExecutable::class);
    $view->storage = $storage;
    $view->argument = $orderId
      ? ['order_id' => new \stdClass()]
      : [];
    return $view;
  }

}
