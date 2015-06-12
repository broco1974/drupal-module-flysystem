<?php

/**
 * @file
 * Contains \Drupal\Tests\flysystem\Unit\Asset\CssCollectionOptimizerTest.
 */

namespace Drupal\Tests\flysystem\Unit\Asset {

use Drupal\Core\Asset\CssOptimizer;
use Drupal\Tests\UnitTestCase;
use Drupal\flysystem\Asset\AssetDumper;
use Drupal\flysystem\Asset\CssCollectionOptimizer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use org\bovigo\vfs\vfsStream;

/**
 * @coversDefaultClass \Drupal\flysystem\Asset\CssCollectionOptimizer
 * @group flysystem
 */
class CssCollectionOptimizerTest extends UnitTestCase {

  /**
   * @covers ::deleteAll
   */
  public function test() {
    if (!defined('REQUEST_TIME')) {
      define('REQUEST_TIME', time());
    }

    vfsStream::setup('flysystem');
    file_put_contents('vfs://flysystem/test.js', 'asdfasdf');

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub([
      'system.performance' => ['stale_file_threshold' => -1],
    ]));

    \Drupal::setContainer($container);

    $grouper = $this->prophesize('Drupal\Core\Asset\AssetCollectionGrouperInterface');
    $dumper = new AssetDumper();
    $optimizer = new CssOptimizer();
    $state = $this->getMock('Drupal\Core\State\StateInterface');

    $optimizer = new CssCollectionOptimizer($grouper->reveal(), $optimizer, $dumper, $state);

    $optimizer->deleteAll();
    $this->assertFalse(file_exists('vfs://flysystem/test.js'));
  }

}
}

namespace {
  if (!function_exists('file_scan_directory')) {
    function file_scan_directory($dir, $mask, array $options) {
      $options['callback']('vfs://flysystem/test.js');
    }
  }

  if (!function_exists('file_unmanaged_delete')) {
    function file_unmanaged_delete($uri) {
      unlink($uri);
    }
  }
}
