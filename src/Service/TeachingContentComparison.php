<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

/** Compares JSON teaching documents without PHP's numeric-string coercion. */
final class TeachingContentComparison {

  /**
   * Ignores object property order, preserving list order and scalar types.
   *
   * Decode objects as objects so an empty object never becomes an empty list.
   * JSON_PRESERVE_ZERO_FRACTION also distinguishes a JSON integer from a float.
   * Invalid documents never qualify as unchanged teaching content.
   */
  public static function same(string $first, string $second): bool {
    try {
      $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
      return json_encode(self::canonicalize(json_decode($first, FALSE, 32, JSON_THROW_ON_ERROR)), $flags, 32)
        === json_encode(self::canonicalize(json_decode($second, FALSE, 32, JSON_THROW_ON_ERROR)), $flags, 32);
    }
    catch (\JsonException) {
      return FALSE;
    }
  }

  private static function canonicalize(mixed $value): mixed {
    if ($value instanceof \stdClass) {
      $properties = get_object_vars($value);
      ksort($properties, SORT_STRING);
      foreach ($properties as &$property) {
        $property = self::canonicalize($property);
      }
      unset($property);
      return (object) $properties;
    }
    if (is_array($value)) {
      return array_map(self::canonicalize(...), $value);
    }
    return $value;
  }

}
