<?php

/**
 * @file
 * Contains \Drupal\flysystem\FlysystemBridge.
 */

namespace Drupal\flysystem;

use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;

/**
 * An adapter for Flysystem to StreamWrapperInterface.
 */
class FlysystemBridge implements StreamWrapperInterface {

  use UrlGeneratorTrait;

  protected $uri;

  protected $listing;

  protected static $lockOptions = [LOCK_SH, LOCK_EX, LOCK_UN, LOCK_NB];

  /**
   * A map from adapter type to adapter factory.
   *
   * @var array
   *
   * @todo  Figure out a way for other modules to register adapters.
   */
  protected static $adapterMap = [
    'zip' => 'Drupal\flysystem\AdapterFactory\Zip',
    'sftp' => 'Drupal\flysystem\AdapterFactory\Sftp',
    'local' => 'Drupal\flysystem\AdapterFactory\Local',
    's3' => 'Drupal\flysystem\AdapterFactory\S3',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::WRITE_VISIBLE;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return t('Flysystem: @scheme', ['@scheme' => $this->getScheme()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('Flysystem: @scheme', ['@scheme' => $this->getScheme()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * {@inheritdoc}
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    list($scheme, $path) = explode('://', $this->uri, 2);
    $path = str_replace('\\', '/', $path);
    return $this->url('flysystem.download', ['scheme' => $scheme, 'path' => $path], ['absolute' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function realpath() {
    return FALSE;
  }

  protected function getScheme($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    return substr($uri, 0, strpos($uri, '://'));
  }

  /**
   * Returns the local writable target of the resource within the stream.
   *
   * @param string $uri
   *   (optional) The URI.
   *
   * @return string
   *   The path appropriate for use with Flysystem.
   */
  protected function getTarget($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    $pos = strpos($uri, '://');

    // Remove scheme if it exists.
    if ($pos !== FALSE) {
      $uri = substr($uri, $pos + 3);
    }

    return trim($uri, '\/');
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    list($scheme, $target) = explode('://', $uri, 2);
    // If there's no scheme, assume a regular directory path.
    if (!isset($target)) {
      $target = $scheme;
      $scheme = NULL;
    }

    $dirname = ltrim(dirname($target), '\/');

    if ($dirname === '.') {
      $dirname = '';
    }

    return isset($scheme) ? $scheme . '://' . $dirname : $dirname;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir() {
    unset($this->listing);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($uri, $options) {
    $this->uri = $uri;
    $this->listing = $this->getFilesystem()->listContents($this->getTarget());
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir() {
    $current = current($this->listing);
    next($this->listing);
    return $current ? $current['path'] : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir() {
    reset($this->listing);
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($uri, $mode, $options) {
    $this->uri = $uri;
    // @todo mode handling.
    // $recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);
    $success = $this->getFilesystem()->createDir($this->getTarget());
    return $success;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to) {
    return $this->getFilesystem()->rename($this->getTarget($path_from), $this->getTarget($path_to));
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($uri, $options) {
    $this->uri = $uri;
    return $this->getFilesystem()->deleteDir($this->getTarget());
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as) {
    return $this->handle ?: FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close() {
    $this->getFilesystem()->putStream($this->getTarget(), $this->handle);
    fclose($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof() {
    return feof($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush() {
    // Calling putStream() will rewind our handle. flush() shouldn't change the
    // position of the file.
    $pos = ftell($this->handle);

    $success = $this->getFilesystem()->putStream($this->getTarget(), $this->handle);

    fseek($this->handle, $pos);

    return $success;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation) {
    if (in_array($operation, static::$lockOptions)) {
      return flock($this->handle, $operation);
    }

    return TRUE;
  }
  /**
   * {@inheritdoc}
   */
  public function stream_metadata($uri, $option, $value) {
    $this->uri = $uri;
    // $path = $this->getTarget();

    switch ($option) {
      case STREAM_META_ACCESS:
        return TRUE;
    }
    // @todo
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->uri = $uri;
    $path = $this->getTarget();

    $this->handle = fopen('php://temp', 'r+');

    try {
      $reader = $this->getFilesystem()->readStream($path);

      // Some adapters are read only streams, so we can't depend on writing to
      // them.
      stream_copy_to_stream($reader, $this->handle);
      fclose($reader);
      rewind($this->handle);
    }
    catch (FileNotFoundException $e) {
    }

    if ((bool) $this->handle && $options & STREAM_USE_PATH) {
      $opened_path = $path;
    }

    return (bool) $this->handle;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count) {
    return fread($this->handle, $count);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET) {
    return !fseek($this->handle, $offset, $whence);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2) {
    switch ($option) {
      case STREAM_OPTION_BLOCKING:
        return stream_set_blocking($this->handle, $arg1);

      case STREAM_OPTION_READ_TIMEOUT:
        return stream_set_timeout($this->handle, $arg1, $arg2);

      case STREAM_OPTION_WRITE_BUFFER:
        return stream_set_write_buffer($this->handle, $arg1, $arg2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat() {
    return fstat($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell() {
    return ftell($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size) {
    return ftruncate($this->handle, $new_size);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data) {
    return fwrite($this->handle, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($uri) {
    $this->uri = $uri;
    $this->getFilesystem()->delete($this->getTarget());
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($uri, $flags) {
    $this->uri = $uri;

    $ret = [
      'dev' => 0,
      'ino' => 0,
      'mode' => 0,
      'nlink' => 0,
      'uid' => 0,
      'gid' => 0,
      'rdev' => 0,
      'size' => 0,
      'atime' => REQUEST_TIME,
      'mtime' => 0,
      'ctime' => 0,
      'blksize' => -1,
      'blocks' => -1,
    ];

    try {
      $metadata = $this->getFilesystem()->getMetadata($this->getTarget());
    }
    catch (FileNotFoundException $e) {
      return FALSE;
    }

    if ($metadata['type'] === 'dir') {
      // Mode 0777.
      $ret['mode'] = 16895;
    }
    elseif ($metadata['type'] === 'file') {
      // Mode 0666.
      $ret['mode'] = 33204;
    }

    if (isset($metadata['size'])) {
      $ret['size'] = $metadata['size'];
    }
    if (isset($metadata['timestamp'])) {
      $ret['mtime'] = $metadata['timestamp'];
      $ret['ctime'] = $metadata['timestamp'];
    }

    return array_merge(array_values($ret), $ret);
  }

  protected function getNewAdapter() {
    $schemes = Settings::get('flysystem', []);
    $scheme = $this->getScheme();

    $type = isset($schemes[$scheme]['type']) ? $schemes[$scheme]['type'] : '';
    $config = isset($schemes[$scheme]['config']) ? $schemes[$scheme]['config'] : [];

    if (isset(static::$adapterMap[$type])) {
      $factory = static::$adapterMap[$type];
      return $factory::create($config);
    }

    return new NullAdapter();
  }

  protected function getFilesystem() {
    if (!isset($this->filesystem)) {
      $this->filesystem = new Filesystem($this->getNewAdapter());
    }

    return $this->filesystem;
  }

}