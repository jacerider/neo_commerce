<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_commerce\Form\AjaxFormErrors;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ARIA wiring applied to an ajax form before it is sent back.
 */
#[Group('neo_commerce')]
#[CoversClass(AjaxFormErrors::class)]
class AjaxFormErrorsTest extends UnitTestCase {

  /**
   * Invalid elements are marked and pointed at their message.
   */
  public function testWiresInvalidElement(): void {
    $form = [
      'quantity' => [
        '#parents' => ['quantity'],
        '#id' => 'edit-quantity',
        '#errors' => 'Quantity is required.',
      ],
    ];
    $idMap = [];
    AjaxFormErrors::wire($form, ['quantity' => 'Quantity is required.'], $idMap);

    $attributes = $form['quantity']['#attributes'];
    $this->assertSame('true', $attributes['aria-invalid']);
    $this->assertSame('edit-quantity--error', $attributes['aria-describedby']);
    $this->assertSame('edit-quantity--error', $form['quantity']['#error_id']);
    $this->assertSame(['quantity' => 'edit-quantity'], $idMap);
  }

  /**
   * Nested elements are reached, and their error key is the #parents path.
   */
  public function testWiresNestedElement(): void {
    $form = [
      'attributes' => [
        'colour' => [
          '#parents' => ['attributes', 'colour'],
          '#id' => 'edit-attributes-colour',
          '#errors' => 'Choose a colour.',
        ],
      ],
    ];
    $idMap = [];
    AjaxFormErrors::wire(
      $form,
      ['attributes][colour' => 'Choose a colour.'],
      $idMap
    );

    $this->assertSame(
      'true',
      $form['attributes']['colour']['#attributes']['aria-invalid']
    );
    $this->assertSame(
      ['attributes][colour' => 'edit-attributes-colour'],
      $idMap
    );
  }

  /**
   * An existing aria-describedby is added to, not replaced.
   *
   * A field with a description already points at it; overwriting the
   * attribute would trade the error message for the help text.
   */
  public function testPreservesExistingDescribedBy(): void {
    $form = [
      'quantity' => [
        '#parents' => ['quantity'],
        '#id' => 'edit-quantity',
        '#errors' => 'Too many.',
        '#attributes' => ['aria-describedby' => 'edit-quantity--description'],
      ],
    ];
    $idMap = [];
    AjaxFormErrors::wire($form, ['quantity' => 'Too many.'], $idMap);

    $this->assertSame(
      'edit-quantity--description edit-quantity--error',
      $form['quantity']['#attributes']['aria-describedby']
    );
  }

  /**
   * Without a rendered message there is nothing to describe.
   *
   * Pointing aria-describedby at an id that never lands leaves the control
   * announced as invalid with no reason given, which is worse than silence.
   */
  public function testSkipsDescribedByWithoutMessage(): void {
    $form = [
      'quantity' => [
        '#parents' => ['quantity'],
        '#id' => 'edit-quantity',
      ],
    ];
    $idMap = [];
    AjaxFormErrors::wire($form, ['quantity' => 'Required.'], $idMap);

    $this->assertSame('true', $form['quantity']['#attributes']['aria-invalid']);
    $this->assertArrayNotHasKey(
      'aria-describedby',
      $form['quantity']['#attributes']
    );
    $this->assertArrayNotHasKey('#error_id', $form['quantity']);
    // It is still linkable from the summary.
    $this->assertSame(['quantity' => 'edit-quantity'], $idMap);
  }

  /**
   * Required elements advertise themselves whether or not they are invalid.
   */
  public function testMarksRequiredElements(): void {
    $form = [
      'quantity' => [
        '#parents' => ['quantity'],
        '#id' => 'edit-quantity',
        '#required' => TRUE,
      ],
    ];
    $idMap = [];
    AjaxFormErrors::wire($form, [], $idMap);

    $this->assertSame(
      'true',
      $form['quantity']['#attributes']['aria-required']
    );
    $this->assertArrayNotHasKey(
      'aria-invalid',
      $form['quantity']['#attributes']
    );
  }

  /**
   * Valid elements are left alone.
   */
  public function testLeavesValidElementsUntouched(): void {
    $form = [
      'quantity' => [
        '#parents' => ['quantity'],
        '#id' => 'edit-quantity',
      ],
    ];
    $idMap = [];
    AjaxFormErrors::wire($form, ['other' => 'Nope.'], $idMap);

    $this->assertArrayNotHasKey('#attributes', $form['quantity']);
    $this->assertSame([], $idMap);
  }

  /**
   * Summary entries link to the field when one was found.
   */
  public function testSummaryLinksToFields(): void {
    $summary = AjaxFormErrors::summary(
      ['quantity' => 'Quantity is required.'],
      ['quantity' => 'edit-quantity'],
      $this->translation()
    );

    $item = $summary['list']['#items'][0];
    $this->assertSame('a', $item['#tag']);
    $this->assertSame('Quantity is required.', $item['#value']);
    $this->assertSame('#edit-quantity', $item['#attributes']['href']);
  }

  /**
   * An error with no element behind it is still listed, just not linked.
   */
  public function testSummaryListsUnmatchedErrors(): void {
    $summary = AjaxFormErrors::summary(
      ['' => 'The form could not be submitted.'],
      [],
      $this->translation()
    );

    $this->assertSame(
      ['#markup' => 'The form could not be submitted.'],
      $summary['list']['#items'][0]
    );
  }

  /**
   * No errors means no summary, so the container renders as a silent shell.
   */
  public function testSummaryIsEmptyWithoutErrors(): void {
    $this->assertSame([], AjaxFormErrors::summary([], [], $this->translation()));
  }

  /**
   * A translation service whose formatPlural just echoes the singular.
   */
  protected function translation(): TranslationInterface {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('formatPlural')->willReturnArgument(1);
    return $translation;
  }

}
