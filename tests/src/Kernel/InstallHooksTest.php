<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drupal\neo_alchemist\ThemeComponentInstaller;
use Drupal\neo_commerce\Hook\InstallHooks;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that installing the module hands the cart component to the theme.
 *
 * This writes real files, so it targets a disposable fixture theme shipped
 * alongside the test (tests/themes/nc_cart_target) and removes what it wrote
 * in tearDown. Pointing it at a real theme would leave a cart_quick behind in
 * someone's checkout.
 *
 * The three literals the module hands the claim -- the shipped default, the
 * config name and the config key -- are all pinned by the one assertion that
 * the setting ends at the theme's copy: get any of them wrong and nothing is
 * repointed.
 */
#[Group('neo_commerce')]
class InstallHooksTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'neo',
    'neo_build',
    'neo_color',
    'neo_icon',
    'neo_settings',
    'neo_modal',
    'neo_tooltip',
    'neo_alchemist',
    'neo_commerce',
  ];

  /**
   * The theme the cart component is claimed for.
   */
  const THEME = 'nc_cart_target';

  /**
   * The absolute path the test writes to.
   */
  protected string $target;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The theme goes in before the module's config does: installing a theme
    // fires the very hook under test, and a claim against a settings object
    // that does not exist yet writes nothing.
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->installConfig(['neo_commerce']);
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->target = $this->container->getParameter('app.root') . '/'
      . $this->container->get('extension.list.theme')->getPath(self::THEME)
      . '/components';
    $this->removeTarget();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTarget();
    parent::tearDown();
  }

  /**
   * Deletes anything the test wrote into the fixture theme.
   */
  protected function removeTarget(): void {
    if (isset($this->target) && is_dir($this->target)) {
      $this->container->get('file_system')->deleteRecursive($this->target);
    }
  }

  /**
   * Gets the hook class as the container builds it.
   */
  protected function hooks(): InstallHooks {
    return $this->container->get(InstallHooks::class);
  }

  /**
   * Gets the component the cart summary is built from.
   */
  protected function cartComponent(): ?string {
    return $this->config('neo_commerce.settings')->get('cart_component');
  }

  /**
   * Tests that a theme install hands the cart component to the theme.
   */
  public function testClaimsTheCartComponentForTheTheme(): void {
    $this->assertSame('neo_commerce:cart_quick', $this->cartComponent());

    $this->hooks()->themesInstalled([self::THEME]);

    $this->assertSame(
      self::THEME . ':cart_quick',
      $this->cartComponent(),
      'The setting names the theme copy, not the module copy.'
    );
    $this->assertFileExists(
      $this->target . '/cart_quick/cart_quick.component.yml',
      'The claim swept the component into the theme on its way.'
    );
  }

  /**
   * Tests that a component a site builder already chose is left alone.
   *
   * Both hooks can fire on one request and both fire again on the next
   * install, so anything other than the shipped default is a decision someone
   * made and a claim never reverts one.
   */
  public function testExistingChoiceIsKept(): void {
    $this->config('neo_commerce.settings')
      ->set('cart_component', self::THEME . ':something_else')
      ->save();

    $this->hooks()->modulesInstalled(['neo_commerce'], FALSE);

    $this->assertSame(
      self::THEME . ':something_else',
      $this->cartComponent()
    );
  }

  /**
   * Tests that nothing happens while config is syncing.
   *
   * A config import must not write to the filesystem, and must not edit the
   * configuration it is in the middle of importing.
   */
  public function testSyncingDoesNothing(): void {
    $this->hooks()->modulesInstalled(['neo_commerce'], TRUE);

    $this->assertSame('neo_commerce:cart_quick', $this->cartComponent());
    $this->assertDirectoryDoesNotExist(
      $this->target,
      'A syncing import wrote nothing into the theme.'
    );
  }

  /**
   * Tests that the hook class and its service declare only the installer.
   *
   * The algorithm the SDC plugin manager and the config factory were there
   * for now lives in neo_alchemist, and a declared dependency nothing reads
   * is a lie about what the class needs.
   */
  public function testOnlyTheInstallerIsDeclared(): void {
    $constructor = (new \ReflectionClass(InstallHooks::class))
      ->getConstructor();
    $parameters = $constructor->getParameters();

    $this->assertCount(1, $parameters);
    $this->assertSame(
      ThemeComponentInstaller::class,
      $parameters[0]->getType()?->getName()
    );

    $services = Yaml::decode($this->moduleFile('neo_commerce.services.yml'));
    $this->assertSame(
      ['@neo_alchemist.theme_component_installer'],
      $services['services'][InstallHooks::class]['arguments']
    );
  }

  /**
   * Tests that the module requires an neo_alchemist that carries the claim.
   *
   * Without the constraint a site can install this module against a release
   * with no claimComponent(), which fatals on install.
   */
  public function testRequiresTheAlchemistCarryingTheClaim(): void {
    $composer = Json::decode($this->moduleFile('composer.json'));

    $this->assertSame(
      '^1.2',
      $composer['require']['jacerider/neo_alchemist']
    );
  }

  /**
   * Reads a file from the module directory.
   */
  protected function moduleFile(string $name): string {
    $path = $this->container->getParameter('app.root') . '/'
      . $this->container->get('extension.list.module')->getPath('neo_commerce')
      . '/' . $name;
    return (string) file_get_contents($path);
  }

}
