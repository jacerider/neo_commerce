<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
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
   */
  public function __construct(
    protected ThemeComponentInstaller $installer,
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
   * The claim sweeps, resolves the theme and only ever upgrades the shipped
   * default; once a site builder has chosen a component their choice stands,
   * which is what makes this safe to run from more than one hook.
   */
  protected function claimThemeComponent(): void {
    $this->installer->claimComponent(
      self::DEFAULT_COMPONENT,
      'neo_commerce.settings',
      'cart_component',
    );
  }

}
