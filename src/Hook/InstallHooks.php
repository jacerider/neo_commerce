<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\ThemeComponentInstaller;

/**
 * Install-time hooks for neo_commerce.
 */
class InstallHooks {

  /**
   * The component this module ships and the theme is meant to take over.
   */
  const COMPONENT_NAME = 'cart_quick';

  /**
   * The shipped default, which is the only value safe to overwrite.
   */
  const DEFAULT_COMPONENT = 'neo_commerce:' . self::COMPONENT_NAME;

  /**
   * Constructs the hook handler.
   *
   * @param \Drupal\neo_alchemist\ThemeComponentInstaller $installer
   *   The theme component installer.
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentManager
   *   The SDC plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected ThemeComponentInstaller $installer,
    protected ComponentPluginManager $componentManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed', order: new OrderAfter(modules: ['neo_alchemist']))]
  public function modulesInstalled(array $modules, bool $isSyncing): void {
    if ($isSyncing) {
      // A config import must not write to the filesystem, and must not edit
      // the configuration it is in the middle of importing.
      return;
    }
    $this->claimThemeComponent();
  }

  /**
   * Implements hook_themes_installed().
   *
   * Core installs every module before any theme during a profile install, so
   * at hook_modules_installed() time there may be no theme to eject into.
   * Running again once a theme exists makes the result independent of order.
   */
  #[Hook('themes_installed', order: new OrderAfter(modules: ['neo_alchemist']))]
  public function themesInstalled(array $themeList): void {
    $this->claimThemeComponent();
  }

  /**
   * Repoints the cart block at the theme's own copy of the component.
   *
   * Only ever upgrades the shipped default. Once a site builder has chosen a
   * component their choice stands, which is what makes this safe to run from
   * more than one hook.
   */
  protected function claimThemeComponent(): void {
    // Idempotent, and cheap when there is nothing to copy. Calling it here as
    // well as letting neo_alchemist call it removes any dependence on which
    // module's hook implementation happens to run first.
    $this->installer->installAll();

    $theme = $this->installer->resolveTheme();
    if (!$theme) {
      return;
    }
    $config = $this->configFactory->getEditable('neo_commerce.settings');
    if ($config->get('cart_component') !== self::DEFAULT_COMPONENT) {
      return;
    }
    $id = $theme . ':' . self::COMPONENT_NAME;
    if ($this->componentManager->hasDefinition($id)) {
      $config->set('cart_component', $id)->save();
    }
  }

}
