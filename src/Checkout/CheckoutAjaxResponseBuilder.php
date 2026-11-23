<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Checkout;

use Drupal\Core\Ajax\UpdateBuildIdCommand;
use Drupal\Core\Form\FormAjaxResponseBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_commerce\Plugin\Commerce\CheckoutFlow\SinglePage;
use Symfony\Component\HttpFoundation\Request;

/**
 * Narrows Commerce's whole-form ajax refresh on the single-page checkout.
 *
 * Commerce's AjaxFormTrait answers a coupon, a shipping rate or an address
 * recalculation by replacing the entire checkout form. On a single page that
 * would also replace the pane holding Stripe's card iframe and throw away the
 * card the shopper was typing. On this flow only, those responses become one
 * replace per pane, leaving that pane alone, plus the refreshed summary.
 *
 * Every other form, and every other callback on this one, goes to core.
 */
class CheckoutAjaxResponseBuilder implements FormAjaxResponseBuilderInterface {

  /**
   * The method of Commerce's AjaxFormTrait, which redraws the whole form.
   */
  public const WHOLE_FORM = 'ajaxRefreshForm';

  /**
   * The payment information pane's callback for a change of payment option.
   *
   * The gateway's own pane (Stripe's card form) follows the option, so it is
   * redrawn too.
   */
  public const PAYMENT_OPTION = [
    'Drupal\\commerce_payment\\Plugin\\Commerce\\CheckoutPane\\PaymentInformation',
    'ajaxRefresh',
  ];

  /**
   * Constructs the decorator.
   *
   * @param \Drupal\Core\Form\FormAjaxResponseBuilderInterface $inner
   *   Core's builder.
   * @param \Drupal\neo_commerce\Checkout\CheckoutResponseBuilder $responseBuilder
   *   The checkout response builder.
   */
  public function __construct(
    protected FormAjaxResponseBuilderInterface $inner,
    protected CheckoutResponseBuilder $responseBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildResponse(
    Request $request,
    array $form,
    FormStateInterface $form_state,
    array $commands,
  ) {
    $flow = $form_state->getFormObject();
    [$class, $method] = $this->callback($form_state);
    $paymentOption = $method === self::PAYMENT_OPTION[1]
      && is_a((string) $class, self::PAYMENT_OPTION[0], TRUE);
    if (
      !$flow instanceof SinglePage ||
      ($form['#step_id'] ?? NULL) !== SinglePage::STEP ||
      !($paymentOption || $method === self::WHOLE_FORM)
    ) {
      return $this->inner->buildResponse($request, $form, $form_state, $commands);
    }

    $response = $this->responseBuilder->refreshResponse(
      $form,
      $form_state,
      $flow->getOrder(),
      array_keys($flow->getVisiblePanes(SinglePage::STEP)),
      $paymentOption
    );
    if (isset($form['#build_id_old']) && $form['#build_id_old'] !== $form['#build_id']) {
      $response->addCommand(
        new UpdateBuildIdCommand($form['#build_id_old'], $form['#build_id'])
      );
    }
    foreach ($commands as $command) {
      $response->addCommand($command, TRUE);
    }
    return $response;
  }

  /**
   * Splits the triggering element's ajax callback into class and method.
   *
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return array{0: string|null, 1: string|null}
   *   The class (or object class) and the method, NULL when not a method.
   */
  protected function callback(FormStateInterface $formState): array {
    $callback = $formState->getTriggeringElement()['#ajax']['callback'] ?? NULL;
    if (is_array($callback) && isset($callback[0], $callback[1])) {
      $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
      return [(string) $class, (string) $callback[1]];
    }
    if (is_string($callback) && str_contains($callback, '::')) {
      return explode('::', $callback, 2) + [NULL, NULL];
    }
    return [NULL, NULL];
  }

}
