<?php

/**
 * @file
 * Contains \Drupal\flysystem\Asset\SchemeExtensionTrait.
 */

namespace Drupal\flysystem\Asset;

use Drupal\Core\Site\Settings;

/**
 * Flysystem dependency injection container.
 */
trait SchemeExtensionTrait {

  /**
   * Returns the scheme that should serve an extension.
   *
   * @param string $extension
   *   The extension.
   *
   * @return string
   *   The scheme that should serve the extension.
   */
  public function getSchemeFromExtension($extension) {

    $extension_scheme = 'public';

    foreach (Settings::get('flysystem', []) as $scheme => $configuration) {
      if (!empty($configuration['serve_' . $extension])) {
        // Don't break, the last configured one will win.
        $extension_scheme = $scheme;
      }
    }

    return $extension_scheme;
  }

}
