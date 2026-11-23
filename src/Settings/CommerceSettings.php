<?php

namespace Drupal\neo_commerce\Settings;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_commerce\Cart\CartCopy;
use Drupal\neo_settings\Plugin\SettingsBase;

/**
 * Module settings.
 *
 * Everything the cart integration used to hardcode lives here: the modal
 * preset, the button labels, and the behaviour toggles. The old module read
 * `Settings::get('commerce_ajax_modal')` from settings.php and baked one
 * site's copy ("Update Quote Request") into shared code -- both of which
 * meant a site could only change them by forking the module.
 *
 * Variations are presets, chosen explicitly by id -- the cart block picks
 * one, and the ajax refresh rebuilds from that same id. Conditions are off
 * for exactly that reason: with them on, getActive() returns the first
 * variation that matches, so a preset saved without any conditions would
 * quietly become the default for every cart on the site.
 *
 * @Settings(
 *   id = "neo_commerce",
 *   label = @Translation("Commerce"),
 *   config_name = "neo_commerce.settings",
 *   menu_title = @Translation("Commerce"),
 *   route = "/admin/config/neo/commerce",
 *   admin_permission = "administer neo_commerce",
 *   variation_allow = true,
 *   variation_label = "preset",
 *   variation_label_plural = "presets",
 *   variation_conditions = false,
 *   variation_ordering = false,
 * )
 */
class CommerceSettings extends SettingsBase {

  /**
   * {@inheritdoc}
   *
   * The per-type copy is keyed by order type id, and an order type added
   * after the config was saved has no key yet. A deep merge intersects the
   * submitted values with the stored ones and would drop that new key, so the
   * whole map is taken as submitted.
   */
  protected $strictParents = [['cart_copy_types']];

  /**
   * {@inheritdoc}
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $parents = $form['#parents'];
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];
    $group = implode('][', $parents) . '][tabs';

    $form['add_to_cart'] = [
      '#type' => 'details',
      '#title' => $this->t('Add to cart'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['add_to_cart']['add_to_cart_ajax'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Submit the add to cart form with ajax'),
      '#description' => $this->t('Leave the product page in place when an item is added.'),
      '#default_value' => $this->getValue('add_to_cart_ajax'),
    ];
    $form['add_to_cart']['add_to_cart_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submitting message'),
      '#description' => $this->t('Shown on the button while the request is in flight. Leave empty to disable the button state.'),
      '#default_value' => $this->getValue('add_to_cart_message'),
      '#states' => $this->visibleWhen($parents, 'add_to_cart_ajax'),
    ];
    $form['add_to_cart']['add_to_cart_success'] = [
      '#type' => 'select',
      '#title' => $this->t('On success'),
      '#options' => [
        'modal' => $this->t('Open the cart in a modal'),
        'refresh' => $this->t('Refresh the cart block only'),
        'redirect' => $this->t('Go to the cart page'),
      ],
      '#default_value' => $this->getValue('add_to_cart_success'),
      '#states' => $this->visibleWhen($parents, 'add_to_cart_ajax'),
    ];
    $form['add_to_cart']['add_to_cart_error_summary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show an error summary above the form'),
      '#description' => $this->t('A focusable, screen-reader-announced list linking to each invalid field. Validation messages are rendered inline either way.'),
      '#default_value' => $this->getValue('add_to_cart_error_summary'),
      '#states' => $this->visibleWhen($parents, 'add_to_cart_ajax'),
    ];
    $form['add_to_cart']['add_to_cart_guard'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable the button during a variation refresh'),
      '#description' => $this->t('Stops a shopper adding the previous variation while an option picker is still rebuilding the form.'),
      '#default_value' => $this->getValue('add_to_cart_guard'),
    ];

    $form['cart_form'] = [
      '#type' => 'details',
      '#title' => $this->t('Cart form'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['cart_form']['cart_form_ajax'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update the cart form with ajax'),
      '#default_value' => $this->getValue('cart_form_ajax'),
    ];
    $form['cart_form']['cart_quantity_plus_minus'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use plus/minus quantity controls'),
      '#description' => $this->t('Setting a quantity to zero removes the item, which is Commerce behaviour and is preserved here.'),
      '#default_value' => $this->getValue('cart_quantity_plus_minus'),
    ];
    $form['cart_form']['cart_remove_icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Remove icon'),
      '#description' => $this->t('Leave empty to keep the text button.'),
      '#default_value' => $this->getValue('cart_remove_icon'),
    ];
    $form['cart_form']['cart_remove_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Remove label'),
      '#description' => $this->t('Used as the accessible name when the remove button is icon-only.'),
      '#default_value' => $this->getValue('cart_remove_label'),
    ];
    $form['cart_form']['cart_update_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update button label'),
      '#default_value' => $this->getValue('cart_update_label'),
    ];
    $form['cart_form']['cart_checkout_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Checkout button label'),
      '#default_value' => $this->getValue('cart_checkout_label'),
    ];

    $this->buildCartPageForm($form, $parents, $group);

    $form['cart_block'] = [
      '#type' => 'details',
      '#title' => $this->t('Cart block'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['cart_block']['cart_component'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Component'),
      '#description' => $this->t('The single directory component that renders the cart block, as <em>provider:name</em>. The theme copy takes over automatically once it is ejected.'),
      '#default_value' => $this->getValue('cart_component'),
      '#required' => TRUE,
    ];
    $form['cart_block']['cart_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->getValue('cart_label'),
    ];
    $form['cart_block']['cart_icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#default_value' => $this->getValue('cart_icon'),
    ];
    $form['cart_block']['cart_display'] = [
      '#type' => 'select',
      '#title' => $this->t('Display'),
      '#options' => [
        'text' => $this->t('Icon and item count text'),
        'badge' => $this->t('Icon with the count in a badge'),
      ],
      '#description' => $this->t('The badge shows the number alone, and is hidden while the cart is empty. Either way the link is still announced as "Cart, 2 items".'),
      '#default_value' => $this->getValue('cart_display'),
    ];
    $form['cart_block']['cart_dropdown'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the cart contents in a dropdown'),
      '#default_value' => $this->getValue('cart_dropdown'),
    ];
    $form['cart_block']['cart_show_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the label beside the icon'),
      '#description' => $this->t('Prints the label (for example "Cart") next to the icon. With the badge display the badge then sits over the icon and label together.'),
      '#default_value' => $this->getValue('cart_show_label'),
    ];
    $form['cart_block']['cart_badge_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the badge when the cart is empty'),
      '#description' => $this->t('Keeps a "0" badge on an empty cart instead of hiding it.'),
      '#default_value' => $this->getValue('cart_badge_empty'),
      '#states' => [
        'visible' => [
          ':input[name="' . $this->fieldName($parents, 'cart_display') . '"]' => ['value' => 'badge'],
        ],
      ],
    ];
    $form['cart_block']['cart_show_if_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the block when the cart is empty'),
      '#default_value' => $this->getValue('cart_show_if_empty'),
    ];

    $form['modal'] = [
      '#type' => 'details',
      '#title' => $this->t('Modal'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['modal']['cart_link_modal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open the cart in a modal from the cart summary link'),
      '#description' => $this->t('Off means the cart summary link navigates to the cart page. The link keeps its href either way, so it still works before the page finishes loading.'),
      '#default_value' => $this->getValue('cart_link_modal'),
    ];
    $form['modal']['modal_preset'] = [
      '#type' => 'neo_settings_variation',
      '#title' => $this->t('Preset'),
      '#settings_repository_id' => 'neo_modal.settings',
      '#default_value' => $this->getValue('modal_preset'),
    ];
    $form['modal']['modal_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $this->getValue('modal_title'),
    ];
    $form['modal']['modal_icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#default_value' => $this->getValue('modal_icon'),
    ];
    $form['modal']['modal_smart_actions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Smart actions'),
      '#description' => $this->t('Lift the cart form actions into the modal footer.'),
      '#default_value' => $this->getValue('modal_smart_actions'),
    ];
    $form['modal']['modal_input_focus'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Focus the first input on open'),
      '#description' => $this->t('Off by default: a cart has nothing to type into, and focusing a quantity field reads out of context.'),
      '#default_value' => $this->getValue('modal_input_focus'),
    ];

    $form['products'] = [
      '#type' => 'details',
      '#title' => $this->t('Products'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['products']['product_category_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Category field'),
      '#description' => $this->t('The product field holding the category. Its first value decides the breadcrumb and the related products fallback. The product breadcrumb applies only when this is set.'),
      '#options' => $this->productReferenceFields('taxonomy_term'),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $this->getValue('product_category_field'),
    ];
    $form['products']['product_related_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Related products field'),
      '#description' => $this->t('Products an editor picks to show as related. When it is empty, other products from the first category are shown instead.'),
      '#options' => $this->productReferenceFields('commerce_product'),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $this->getValue('product_related_field'),
    ];
    $form['products']['product_card_component'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product card component'),
      '#description' => $this->t('Renders a product shown in the <em>Card</em> view mode, as <em>provider:name</em>. The theme copy takes over automatically once it is ejected.'),
      '#default_value' => $this->getValue('product_card_component'),
    ];
    $form['products']['product_image_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Variation image field'),
      '#description' => $this->t('A media field on the variation. Its first image is the card image; with none the card shows the first letter of the name.'),
      '#default_value' => $this->getValue('product_image_field'),
    ];
    $form['products']['product_tagline_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product tagline field'),
      '#default_value' => $this->getValue('product_tagline_field'),
    ];
    $form['products']['product_card_options_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Choose Options label'),
      '#description' => $this->t('The card button of a product with several variations, linking to the product page. A product with one variation adds straight to the cart.'),
      '#default_value' => $this->getValue('product_card_options_label'),
    ];
    $form['products']['breadcrumb_home'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Start the breadcrumb with Home'),
      '#default_value' => $this->getValue('breadcrumb_home'),
    ];
    $form['products']['breadcrumb_root_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Breadcrumb root label'),
      '#description' => $this->t('The crumb before the category lineage, such as "The Market". Leave empty for none.'),
      '#default_value' => $this->getValue('breadcrumb_root_label'),
    ];
    $form['products']['breadcrumb_root_link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Breadcrumb root link'),
      '#description' => $this->t('A path on this site, such as /market.'),
      '#default_value' => $this->getValue('breadcrumb_root_link'),
    ];
    $form['products']['breadcrumb_current'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('End the breadcrumb with the current product or category'),
      '#default_value' => $this->getValue('breadcrumb_current'),
    ];

    return $form;
  }

  /**
   * Builds the cart page tab.
   *
   * @param array $form
   *   The settings form.
   * @param array $parents
   *   The shared form parents.
   * @param string $group
   *   The vertical tabs group.
   */
  protected function buildCartPageForm(array &$form, array $parents, string $group): void {
    $form['cart_page'] = [
      '#type' => 'details',
      '#title' => $this->t('Cart page'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $page = &$form['cart_page'];

    $page['cart_page_component'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cart page component'),
      '#description' => $this->t('Renders one cart, as <em>provider:name</em>. The theme copy takes over automatically once it is ejected.'),
      '#default_value' => $this->getValue('cart_page_component'),
      '#required' => TRUE,
    ];
    $page['cart_overview_component'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cart overview component'),
      '#description' => $this->t('Renders the overview a shopper with more than one cart sees at /cart.'),
      '#default_value' => $this->getValue('cart_overview_component'),
      '#required' => TRUE,
    ];
    $page['cart_line_component'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cart line component'),
      '#description' => $this->t('Renders one line of a cart: image, name, price, quantity and remove.'),
      '#default_value' => $this->getValue('cart_line_component'),
      '#required' => TRUE,
    ];
    $page['cart_quantity_instant'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Apply quantity changes immediately'),
      '#description' => $this->t('Plus, minus and typed quantities update the cart without pressing the update button, which is then hidden.'),
      '#default_value' => $this->getValue('cart_quantity_instant'),
    ];
    $page['cart_overview_thumbnail_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnails per cart on the overview'),
      '#description' => $this->t('Further items are counted in a "+N" chip.'),
      '#min' => 1,
      '#default_value' => $this->getValue('cart_overview_thumbnail_limit'),
    ];
    $page['simple_layout_cart'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Simple page layout on cart pages'),
      '#description' => $this->t('Replace the site header and footer on /cart with a bar holding the logo and a continue shopping link. Needs a theme with page--simple.html.twig (neo_base has one).'),
      '#default_value' => $this->getValue('simple_layout_cart'),
    ];
    $page['simple_layout_checkout'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Simple page layout during checkout'),
      '#description' => $this->t('The same bar on every checkout step, linking back to the cart (and on to shopping once the order is placed).'),
      '#default_value' => $this->getValue('simple_layout_checkout'),
    ];
    $page['cart_explainer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Explainer'),
      '#description' => $this->t('Shown under the carts when a shopper has more than one, for example why each checks out on its own. Allows @tags. Leave empty for none.', [
        '@tags' => $this->allowedTagsText(),
      ]),
      '#rows' => 3,
      '#default_value' => $this->getValue('cart_explainer'),
    ];

    $page['cart_copy'] = $this->copyElements(
      (array) $this->getValue('cart_copy'),
      array_merge($parents, ['cart_copy']),
      TRUE
    ) + [
      '#type' => 'details',
      '#title' => $this->t('Cart copy'),
      '#description' => $this->t('Used by every cart unless its order type below says otherwise.'),
      '#open' => TRUE,
    ];

    $page['cart_overview'] = $this->overviewElements(
      (array) $this->getValue('cart_overview'),
      array_merge($parents, ['cart_overview'])
    ) + [
      '#type' => 'details',
      '#title' => $this->t('Overview copy'),
      '#description' => $this->t('The page a shopper with more than one cart sees at /cart, the empty state when every cart is empty, and the link back to it from each cart.'),
    ];

    $types = (array) $this->getValue('cart_copy_types');
    $orderTypes = \Drupal::entityTypeManager()
      ->getStorage('commerce_order_type')
      ->loadMultiple();
    foreach ($orderTypes as $id => $orderType) {
      $page['cart_copy_types'][$id] = $this->copyElements(
        (array) ($types[$id] ?? []),
        array_merge($parents, ['cart_copy_types', $id]),
        FALSE
      ) + [
        '#type' => 'details',
        '#title' => $this->t('Order type: @label', [
          '@label' => $orderType->label(),
        ]),
        '#description' => $this->t('Leave a field empty to use the cart copy above.'),
      ];
    }
  }

  /**
   * Builds the fields for one set of cart copy.
   *
   * @param array $values
   *   The stored values.
   * @param array $parents
   *   The parents the values are stored under.
   * @param bool $defaults
   *   Whether these are the defaults, rather than an order type's overrides.
   *
   * @return array
   *   The elements.
   */
  protected function copyElements(array $values, array $parents, bool $defaults): array {
    $text = function (string $key, $title, $description = NULL) use ($values, $parents): array {
      return [
        '#type' => 'textfield',
        '#title' => $title,
        '#description' => $description,
        '#parents' => array_merge($parents, [$key]),
        '#default_value' => $values[$key] ?? '',
      ];
    };
    $link = $this->t('A path on this site such as /market, &lt;front&gt;, or a full URL.');

    $elements = [
      'kicker' => $text('kicker', $this->t('Kicker'), $this->t('The small line above the title.')),
      'title' => $text('title', $this->t('Title'), $defaults ? NULL : $this->t('Also names this cart on the overview. Empty uses the order type label there.')),
      'intro' => $text('intro', $this->t('Intro')),
      'notice' => [
        '#type' => 'textarea',
        '#title' => $this->t('Notice'),
        '#description' => $this->t('A boxed note above the items, such as delivery terms. Allows @tags.', [
          '@tags' => $this->allowedTagsText(),
        ]),
        '#rows' => 2,
        '#parents' => array_merge($parents, ['notice']),
        '#default_value' => $values['notice'] ?? '',
      ],
      'notice_icon' => [
        '#type' => 'neo_icon_select',
        '#title' => $this->t('Notice icon'),
        '#parents' => array_merge($parents, ['notice_icon']),
        '#default_value' => $values['notice_icon'] ?? '',
      ],
      'footnote' => $text('footnote', $this->t('Footnote'), $this->t('Under the order summary.')),
      'checkout_label' => $text('checkout_label', $this->t('Checkout button label'), $defaults ? $this->t('Empty uses the checkout label on the Cart form tab.') : NULL),
      'continue_label' => $text('continue_label', $this->t('Continue shopping label')),
      'continue_url' => $text('continue_url', $this->t('Continue shopping link'), $link),
      'empty_title' => $text('empty_title', $this->t('Empty title')),
      'empty_text' => $text('empty_text', $this->t('Empty text')),
      'empty_cta_label' => $text('empty_cta_label', $this->t('Empty button label')),
      'empty_cta_url' => $text('empty_cta_url', $this->t('Empty button link'), $link),
    ];
    $elements['continue_url']['#element_validate'][] = [static::class, 'validateLink'];
    $elements['empty_cta_url']['#element_validate'][] = [static::class, 'validateLink'];
    return $elements;
  }

  /**
   * Builds the fields for the overview copy.
   *
   * @param array $values
   *   The stored values.
   * @param array $parents
   *   The parents the values are stored under.
   *
   * @return array
   *   The elements.
   */
  protected function overviewElements(array $values, array $parents): array {
    $labels = [
      'kicker' => $this->t('Kicker'),
      'title' => $this->t('Title'),
      'intro' => $this->t('Intro'),
      'empty_title' => $this->t('Empty title'),
      'empty_text' => $this->t('Empty text'),
      'empty_cta_label' => $this->t('Empty button label'),
      'empty_cta_url' => $this->t('Empty button link'),
      'view_label' => $this->t('View cart button label'),
      'back_label' => $this->t('Back link label'),
    ];
    $elements = [];
    foreach ($labels as $key => $title) {
      $elements[$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#parents' => array_merge($parents, [$key]),
        '#default_value' => $values[$key] ?? '',
      ];
    }
    $elements['empty_cta_url']['#element_validate'][] = [static::class, 'validateLink'];
    return $elements;
  }

  /**
   * Validates a link field against what CartCopy::url() accepts.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateLink(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    if ($value !== '' && !CartCopy::url($value)) {
      $form_state->setError($element, new TranslatableMarkup('%title must be a path on this site starting with /, &lt;front&gt;, or a full http(s) URL.', [
        '%title' => $element['#title'],
      ]));
    }
  }

  /**
   * Lists the tags a rich cart value allows, for form descriptions.
   *
   * @return string
   *   The tags, such as "<strong>, <em>".
   */
  protected function allowedTagsText(): string {
    return implode(', ', array_map(
      static fn (string $tag): string => '<' . $tag . '>',
      CartCopy::ALLOWED_TAGS
    ));
  }

  /**
   * Lists the product reference fields that target an entity type.
   *
   * @param string $targetType
   *   The referenced entity type.
   *
   * @return array
   *   Field labels keyed by field name.
   */
  protected function productReferenceFields(string $targetType): array {
    $options = [];
    $fieldManager = \Drupal::service('entity_field.manager');
    $map = $fieldManager->getFieldMapByFieldType('entity_reference');
    $storages = $fieldManager->getFieldStorageDefinitions('commerce_product');
    foreach (array_keys($map['commerce_product'] ?? []) as $name) {
      $storage = $storages[$name] ?? NULL;
      if ($storage && !$storage->isBaseField() && $storage->getSetting('target_type') === $targetType) {
        $options[$name] = $name;
      }
    }
    return $options;
  }

  /**
   * Builds the input name of a setting from the shared form parents.
   *
   * @param array $parents
   *   The shared form parents.
   * @param string $key
   *   The setting key.
   *
   * @return string
   *   The input name.
   */
  protected function fieldName(array $parents, $key) {
    $name = array_shift($parents);
    foreach (array_merge($parents, [$key]) as $part) {
      $name .= '[' . $part . ']';
    }
    return $name;
  }

  /**
   * Builds a #states array showing an element when a checkbox is on.
   *
   * The settings form nests every element under the same #parents, so the
   * selector has to be built from those parents rather than the element's own
   * position in the render array.
   *
   * @param array $parents
   *   The shared form parents.
   * @param string $key
   *   The controlling checkbox key.
   *
   * @return array
   *   A #states array.
   */
  protected function visibleWhen(array $parents, $key) {
    return [
      'visible' => [
        ':input[name="' . $this->fieldName($parents, $key) . '"]' => ['checked' => TRUE],
      ],
    ];
  }

}
