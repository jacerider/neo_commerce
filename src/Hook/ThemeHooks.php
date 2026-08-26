<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_commerce\Form\AjaxFormErrors;

/**
 * Theme hooks for neo_commerce.
 */
class ThemeHooks {

  /**
   * Implements hook_preprocess_form_element().
   *
   * Gives the inline validation message the id that the invalid control's
   * aria-describedby points at. Without a matching element in the document
   * assistive tech drops the reference silently, which leaves the shopper
   * with a field announced as invalid and no reason given.
   *
   * The id goes onto the message content rather than onto a template
   * variable. The module this replaced set an #error_id variable and relied
   * on the form-element template printing it -- no theme in this codebase
   * does, so the reference had been dangling the whole time. Wrapping the
   * content works whatever template renders it.
   */
  #[Hook('preprocess_form_element')]
  public function preprocessFormElement(array &$variables): void {
    $errorId = $variables['element'][AjaxFormErrors::ELEMENT_ERROR_ID] ?? NULL;
    if (!$errorId || empty($variables['errors'])) {
      return;
    }
    $variables['errors'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['id' => $errorId],
      // Already-rendered markup; html_tag passes MarkupInterface through
      // rather than running it back through the admin XSS filter.
      '#value' => $variables['errors'],
    ];
  }

}
