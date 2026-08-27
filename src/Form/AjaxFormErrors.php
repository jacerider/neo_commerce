<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Form;

use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Wires ARIA error attributes onto a form about to be sent back over ajax.
 *
 * A normal page submit reloads the document, so assistive tech re-announces
 * the whole form and finds the errors on its own. An ajax rebuild replaces a
 * fragment in place and announces nothing, so the errors have to be described
 * explicitly: the invalid control gets aria-invalid, points at its own message
 * with aria-describedby, and a focusable summary lists every failure.
 *
 * This runs immediately before the form is re-rendered into the response,
 * which is the only point where #parents are stable, the rendered #id is
 * final, and form_state knows which elements failed.
 *
 * Everything here is pure array manipulation with no container access, which
 * is what makes the one genuinely fiddly piece of the cart integration
 * testable without bootstrapping Drupal.
 */
final class AjaxFormErrors {

  /**
   * Suffix appended to an element id to name its inline error message.
   */
  const ERROR_ID_SUFFIX = '--error';

  /**
   * Render array property carrying the inline error message's id.
   *
   * Read back by neo_commerce's preprocess_form_element hook, which is the
   * only place with access to the rendered message.
   */
  const ELEMENT_ERROR_ID = '#error_id';

  /**
   * Walks a form, wiring ARIA attributes and collecting element ids.
   *
   * @param array $element
   *   The form or element to walk, by reference.
   * @param array<string, string|\Drupal\Component\Render\MarkupInterface> $errors
   *   Errors from FormStateInterface::getErrors(), keyed by the element's
   *   #parents joined with '][', which is the same key form_state builds.
   * @param array<string, string> $idMap
   *   Collects error key => rendered element #id, so the summary can link
   *   straight to the field. Passed by reference and appended to.
   */
  public static function wire(array &$element, array $errors, array &$idMap): void {
    if (!empty($element['#parents'])) {
      $name = implode('][', $element['#parents']);

      // A required control advertises itself whether or not it is currently
      // invalid -- that is what tells a screen reader the field must be
      // filled before the shopper tries to submit, rather than after.
      if (!empty($element['#required'])) {
        $element['#attributes']['aria-required'] = 'true';
      }

      if (isset($errors[$name]) && !empty($element['#id'])) {
        // Cast so the collected map stays the string-to-string map its type
        // declares; a render array's '#id' is always a string, so this is a
        // no-op at runtime and a narrowing for analysis.
        $idMap[$name] = (string) $element['#id'];
        $element['#attributes']['aria-invalid'] = 'true';

        // Only describe the message when there is going to be one. Core sets
        // #errors on the element it renders the inline message for; pointing
        // aria-describedby at an id that never lands leaves the shopper with
        // a control marked invalid and no reason given.
        if (!empty($element['#errors'])) {
          $errorId = $element['#id'] . self::ERROR_ID_SUFFIX;
          $element[self::ELEMENT_ERROR_ID] = $errorId;
          $element['#attributes']['aria-describedby'] =
            self::mergeTokens($element['#attributes']['aria-describedby'] ?? '', $errorId);
        }
      }
    }

    foreach (Element::children($element) as $key) {
      if (is_array($element[$key])) {
        self::wire($element[$key], $errors, $idMap);
      }
    }
  }

  /**
   * Builds the error summary shown above the form.
   *
   * @param array<string, string|\Drupal\Component\Render\MarkupInterface> $errors
   *   Errors from FormStateInterface::getErrors().
   * @param array<string, string> $idMap
   *   The map collected by ::wire().
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service, for the pluralised heading.
   *
   * @return array
   *   A render array to drop into the reserved summary container. Empty when
   *   there are no errors, so the container renders as a silent placeholder.
   */
  public static function summary(array $errors, array $idMap, TranslationInterface $translation): array {
    if (!$errors) {
      return [];
    }

    $items = [];
    foreach ($errors as $name => $message) {
      // A plain fragment anchor rather than #type link: the target is an id
      // in this very fragment, so routing it through Url only to have the
      // unrouted assembler hand back the same '#id' buys nothing and drags
      // the container into what is otherwise pure array work.
      //
      // An error with no element behind it -- a form-level message, or a
      // field removed by an alter hook -- still has to be listed. It just
      // cannot be a link to anywhere.
      $items[] = isset($idMap[$name])
        ? [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $message,
          '#attributes' => [
            'href' => '#' . $idMap[$name],
            'class' => ['form-error-summary__link'],
          ],
        ]
        : ['#markup' => $message];
    }

    return [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $translation->formatPlural(
          count($items),
          '@count error has been found:',
          '@count errors have been found:'
        ),
        '#attributes' => ['class' => ['form-error-summary__title']],
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['form-error-summary__list']],
      ],
    ];
  }

  /**
   * Appends a token to a space separated attribute without duplicating it.
   *
   * @param string $existing
   *   The current attribute value, possibly empty.
   * @param string $token
   *   The token to add.
   *
   * @return string
   *   The merged value.
   */
  protected static function mergeTokens(string $existing, string $token): string {
    $tokens = [];
    if (trim($existing) !== '') {
      $tokens = preg_split('/\s+/', trim($existing), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $tokens[] = $token;
    return implode(' ', array_unique($tokens));
  }

}
