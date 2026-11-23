<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Product;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\media\MediaInterface;

/**
 * Reads the first image of a media field as data for an image prop.
 *
 * Components get images as data rather than as a rendered field, so the
 * template decides the crop with neo_image to fit the box it draws. The
 * product card and the cart lines read the same field the same way.
 *
 * Classes using this need a `$fileUrlGenerator` property holding the
 * file_url_generator service.
 */
trait ImageDataTrait {

  /**
   * Reads the first image of a media field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity holding the media field.
   * @param string $field
   *   The media field name.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the media and file as dependencies.
   *
   * @return array|null
   *   The image prop (src, alt, width, height), or NULL when there is none.
   */
  protected function imageData(FieldableEntityInterface $entity, string $field, CacheableMetadata $cacheability): ?array {
    if ($field === '' || !$entity->hasField($field)) {
      return NULL;
    }
    $media = $entity->get($field)->entity;
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $cacheability->addCacheableDependency($media);
    $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if ($source === '' || $media->get($source)->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\image\Plugin\Field\FieldType\ImageItem $item */
    $item = $media->get($source)->first();
    $file = $item->entity;
    if (!$file) {
      return NULL;
    }
    $cacheability->addCacheableDependency($file);
    return [
      // A root-relative path: the image shape's src only accepts web paths
      // and URLs, and neo_image reads a path as readily as a stream URI.
      'src' => $this->fileUrlGenerator->generateString($file->getFileUri()),
      'alt' => (string) ($item->alt ?? ''),
      'width' => $item->width === NULL ? NULL : (int) $item->width,
      'height' => $item->height === NULL ? NULL : (int) $item->height,
    ];
  }

}
