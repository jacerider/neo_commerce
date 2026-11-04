<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Ajax;

use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\FormBuilderInterface;

/**
 * Builds markup inside an ajax callback as if for a normal page.
 */
trait AjaxFormRequestTrait {

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

}
