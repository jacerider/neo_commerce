<?php

namespace Drupal\neo_commerce\Settings;

use Drupal\Core\Form\FormStateInterface;
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

    return $form;
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
    $name = array_shift($parents);
    foreach (array_merge($parents, [$key]) as $part) {
      $name .= '[' . $part . ']';
    }
    return [
      'visible' => [
        ':input[name="' . $name . '"]' => ['checked' => TRUE],
      ],
    ];
  }

}
