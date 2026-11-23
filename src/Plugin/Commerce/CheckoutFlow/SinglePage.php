<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutFlow;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_commerce\Checkout\CheckoutPage;
use Drupal\neo_commerce\Checkout\CheckoutResponseBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checkout on one page: details, shipping, payment and the summary together.
 *
 * Commerce wants a `payment` and a `complete` step after whatever a flow
 * defines, and `payment_information` and `payment_process` on different
 * steps. This flow adds a single visible step before them.
 *
 * That step is named `review` on purpose. commerce_stripe's Payment Element
 * returns the shopper to `/checkout/{order}/review/return` whatever the flow,
 * and Commerce's return controller refuses a step the order is not on. With
 * the page itself called `review`, the return lands where the shopper left.
 *
 * The step carries a hidden "save" button. It validates and submits every
 * pane, saves the order and stays on the page, so a script can store what the
 * shopper typed before a gateway takes the payment in the browser.
 */
#[CommerceCheckoutFlow(
  id: 'neo_single_page',
  label: new TranslatableMarkup('Single page (Neo)'),
)]
class SinglePage extends CheckoutFlowWithPanesBase {

  /**
   * The step the whole page lives on.
   */
  public const STEP = 'review';

  /**
   * The name of the hidden save button.
   */
  public const SAVE_BUTTON = 'neo_checkout_save';

  /**
   * Panes whose markup must survive a save response untouched.
   *
   * The Payment Element is an iframe mounted by script inside this pane.
   * Replacing the pane would throw away the card the shopper typed.
   */
  public const KEEP_PANES = ['stripe_review'];

  /**
   * The checkout response builder.
   *
   * @var \Drupal\neo_commerce\Checkout\CheckoutResponseBuilder
   */
  protected CheckoutResponseBuilder $responseBuilder;

  /**
   * The checkout page layout.
   *
   * @var \Drupal\neo_commerce\Checkout\CheckoutPage
   */
  protected CheckoutPage $page;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $pane_id,
    $pane_definition,
  ) {
    $instance = parent::create(
      $container,
      $configuration,
      $pane_id,
      $pane_definition
    );
    $instance->responseBuilder = $container->get(
      'neo_commerce.checkout_response'
    );
    $instance->page = $container->get('neo_commerce.checkout_page');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getSteps() {
    $steps = [
      self::STEP => [
        'label' => $this->t('Checkout'),
        'has_sidebar' => TRUE,
      ],
    ] + parent::getSteps();
    // Shown on the button that leaves the page for the payment step.
    $steps['payment']['next_label'] = $this->t('Place order');
    return $steps;
  }

  /**
   * {@inheritdoc}
   *
   * Places the panes a single page needs. Panes left out keep the default
   * step their plugin declares, or end up disabled when this flow has no
   * such step (`login`, `order_information`).
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();
    $configuration['display_checkout_progress'] = FALSE;
    $configuration['panes'] = [
      'contact_information' => ['step' => self::STEP, 'weight' => 0],
      'shipping_information' => ['step' => self::STEP, 'weight' => 5],
      'payment_information' => ['step' => self::STEP, 'weight' => 10],
      'stripe_review' => ['step' => self::STEP, 'weight' => 15],
      'review' => ['step' => '_disabled'],
      'login' => ['step' => '_disabled'],
      'billing_information' => ['step' => '_disabled'],
      'order_summary' => ['step' => '_sidebar', 'weight' => 0],
      'coupon_redemption' => ['step' => '_sidebar', 'weight' => 5],
      'payment_process' => ['step' => 'payment', 'weight' => 0],
      'completion_message' => ['step' => 'complete', 'weight' => 0],
      'completion_register' => ['step' => 'complete', 'weight' => 5],
    ];
    return $configuration;
  }

  /**
   * {@inheritdoc}
   *
   * The page has nothing before it. A gateway that fails, or a Stripe intent
   * that was cancelled, sends the shopper "back", and back is the page.
   */
  public function getPreviousStepId($step_id) {
    $previous = parent::getPreviousStepId($step_id);
    if ($previous === NULL && $step_id === self::STEP) {
      return self::STEP;
    }
    return $previous;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    $step_id = NULL,
  ) {
    if ($step_id === self::STEP) {
      $this->responseBuilder->preselectPaymentGateway(
        $this->getOrder(),
        $this->getPane('payment_information')
      );
    }

    $form = parent::buildForm($form, $form_state, $step_id);
    if ($step_id !== self::STEP) {
      return $form;
    }

    $form['#attributes']['data-neo-checkout'] = '';
    // The server checks every field and answers with inline messages and a
    // summary. The browser's own bubbles would stop the submit before the
    // card form is checked, and show one problem at a time.
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#attached']['library'][] = 'neo_commerce/checkout';

    // Reserved up front so the save response has somewhere to write into,
    // as the add to cart form does.
    $form['neo_error_summary'] = [
      '#type' => 'container',
      '#weight' => -1000,
      '#attributes' => [
        'id' => 'neo-checkout-error-summary',
        'class' => ['form-error-summary'],
        'role' => 'alert',
        'aria-live' => 'assertive',
        'aria-atomic' => 'true',
        'tabindex' => '-1',
      ],
    ];

    if (isset($form['actions']['next'])) {
      $form['actions']['next']['#attributes']['data-neo-checkout-place'] = '';
    }
    $form['actions'][self::SAVE_BUTTON] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#name' => self::SAVE_BUTTON,
      '#weight' => 100,
      '#submit' => ['::saveStep'],
      '#ajax' => [
        'callback' => '::ajaxSave',
        'progress' => ['type' => 'none'],
        'disable-refocus' => TRUE,
      ],
      '#attributes' => [
        'class' => ['visually-hidden'],
        'tabindex' => '-1',
        'aria-hidden' => 'true',
        'data-neo-checkout-save' => '',
      ],
    ];

    $this->responseBuilder->adoptStripe($form, $this->getOrder());
    $this->page->decorate(
      $form,
      $this->getOrder(),
      array_keys($this->getVisiblePanes(self::STEP))
    );
    return $form;
  }

  /**
   * Submit handler for the save button: store the panes, stay on the page.
   *
   * Mirrors the pane loop of CheckoutFlowWithPanesBase::submitForm() without
   * moving the order to the next step.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function saveStep(array &$form, FormStateInterface $form_state): void {
    foreach ($this->getVisiblePanes($form['#step_id']) as $pane_id => $pane) {
      if (isset($form[$pane_id])) {
        $pane->submitPaneForm($form[$pane_id], $form_state, $form);
      }
    }
    if ($this->hasSidebar($form['#step_id'])) {
      foreach ($this->getVisiblePanes('_sidebar') as $pane_id => $pane) {
        if (isset($form['sidebar'][$pane_id])) {
          $pane->submitPaneForm($form['sidebar'][$pane_id], $form_state, $form);
        }
      }
    }
    $this->getOrder()->save();
    // Rebuilding reloads the order, so the response reads what was saved.
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the save button.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function ajaxSave(
    array &$form,
    FormStateInterface $form_state,
  ): AjaxResponse {
    return $this->responseBuilder->saveResponse(
      $form,
      $form_state,
      $this->getOrder(),
      array_keys($this->getVisiblePanes(self::STEP))
    );
  }

}
