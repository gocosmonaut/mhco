<?php

namespace Drupal\fillpdf\FieldMapping;

use Drupal\fillpdf\FieldMapping;

class ImageFieldMapping extends FieldMapping {

  /**
   * @var string
   *
   * The common extension (jpg, png, or gif) corresponding to the type of image
   * data sent through.
   */
  protected $extension;

  /**
   * @param $data
   * @param $extension
   *   The original extension corresponding to the image data. Not all consumers
   *   actually need this information, and if you know that yours doesn't, you
   *   can leave it blank.
   *
   * @throws \InvalidArgumentException
   */
  public function __construct($data, $extension = NULL) {
    parent::__construct($data);

    if (isset($extension) && !in_array($extension, ['jpg', 'png', 'gif'])) {
      throw new \InvalidArgumentException('Extension must be one of: jpg, png, gif.');
    }

    $this->extension = $extension;
  }

  /**
   * @return string
   */
  public function getExtension() {
    return $this->extension;
  }

}
