<?php

/**
 * Class to hold YAML based configurations
 * The model is that multipe books exist corresponding to their own repo.
 * Each chapter is a file
 *  @copyright Australian Greens 2014-16. All rights reserved.
 */
class AgcBook{

  /**
   * The path for this book
   * @return string
   */
  static public function makePath($book) {
    // choose appropriate path for this book
    // currently subsets go into files
    $path = variable_get($book == 'subsets'
      ? 'agc_cache_directory' : 'agc_config_directory', '');
    if ($path) {
      return  $path . '/' . $book;
    }
    else {
      throw new Exception('bad path config');
    }
  }

  private $path;

  /**
   * Verify this is a legal directory or file name
   * @return boolean
   */
  static public function okName($name) {
    return preg_match('/^[a-z][a-z\d_]*$/i', $name);
  }

  function __construct($book) {
    if (!self::okName($book)) {
      throw new Exception('only a-z, digits and underscores allowed, got: "' . $book . '"');
    }
    $this->path = self::makePath($book);
    // if path not present then attempt to create
    if (!(boolean)file_exists($this->path)) {
      mkdir($this->path, 0777, true);
    }
  }

  /**
    * Validat and return the path to a chapter of this book
    * @param $chapter name
    * @returns string path
   */
  public function chapterFile($chapter) {
    if(self::okName($chapter)) {
      return $this->path . '/' . $chapter . '.yml';
    }
    throw new Exception(
      'making file, only a-z, digits and underscores allowed, got "' . $chapter .'"');
  }

  /**
    * Getter for this chapter
    * @param string chapter name
    * @returns complex
   */
  public function get($chapter) {
    $yaml = file_get_contents($this->chapterFile($chapter));
    return sfYaml::load($yaml);
  }

  /**
    * Setter for this chapter
    * @param string chapter name
    * @param complex data
    * @returns boolean|int false = failure
   */
  public function put($chapter, $data) {
    $yaml = sfYaml::dump($data);
    $file = $this->chapterFile($chapter);
    chmod($file, 0777);
    return file_put_contents($file, $yaml);
  }

  /**
    * Getter for this chapter (raw)
    * @param string chapter name
    * @returns string
   */
  public function getRaw($chapter) {
    $data = file_get_contents($this->chapterFile($chapter));
    return ($data);
  }

  /**
    * Setter for this chapter
    * @param string chapter name
    * @param complex data
    * @returns boolean|int false = failure
   */
  public function putRaw($chapter, $data) {
    $file = $this->chapterFile($chapter);
//    chmod($file, 0777);
    return file_put_contents($file, ($data));
  }

  /**
    * How old is this chapter in seconds?
    * @return int|false if file doesn't exist
   */
  public function age($chapter) {
    $filemtime = @filemtime($this->chapterFile($chapter));
    return $filemtime ? REQUEST_TIME - $filemtime : false;
  }

  /**
    * Kabooom - should only be called by testing
   */
  public function destroy() {
//    chmod($this->path, 777);
    file_unmanaged_delete_recursive($this->path);
  }

  /**
   * What are the cache objects in this book?
   * @returns array
   */
  public function listChapters() {
    // use drupal function to scan:
    $directory = file_scan_directory($this->path, '/.*/', ['key' => 'name']);
    // we only want the names:
    return array_keys($directory);
  }

  /**
   * What are the cache objects in this book?
   * @returns boolean success
   */
  public function deleteChapter($chapter) {
    $file = $this->chapterFile($chapter);
    return file_unmanaged_delete($file);
  }

}
