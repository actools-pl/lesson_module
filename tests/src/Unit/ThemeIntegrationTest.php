<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Unit;

use Drupal\actools_lesson\Service\ThemeIntegration;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/** Verifies optional assets follow the active theme, including admin themes. */
#[CoversClass(ThemeIntegration::class)]
#[Group('actools_lesson')]
class ThemeIntegrationTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    $contexts = $this->getMockBuilder(CacheContextsManager::class)->disableOriginalConstructor()->getMock();
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  public function testThemeGateAndCacheVariation(): void {
    foreach ([
      ['actools_bs5', [], TRUE],
      ['site_child', ['actools_bs5' => new \stdClass()], TRUE],
      ['claro', [], FALSE],
      ['unrelated_child', ['stark' => new \stdClass()], FALSE],
    ] as [$name, $bases, $expected]) {
      $manager = $this->createMock(ThemeManagerInterface::class);
      $manager->method('getActiveTheme')->willReturn(new ActiveTheme(['name' => $name, 'base_theme_extensions' => $bases]));
      $integration = new ThemeIntegration($manager);
      $build = ['#cache' => ['tags' => ['node:7'], 'contexts' => ['user.permissions']], '#attached' => ['library' => ['actools_lesson/reader']]];
      $integration->attach($build);
      $integration->attach($build);
      $this->assertContains('theme', $build['#cache']['contexts']);
      $this->assertContains('user.permissions', $build['#cache']['contexts']);
      $this->assertSame(['node:7'], $build['#cache']['tags']);
      $this->assertContains('actools_lesson/reader', $build['#attached']['library']);
      $this->assertSame($expected ? 1 : 0, count(array_keys($build['#attached']['library'], 'actools_lesson/bs5_integration', TRUE)), $name);
    }
  }

}
