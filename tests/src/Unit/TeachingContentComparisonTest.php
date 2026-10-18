<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Unit;

use Drupal\actools_lesson\Service\TeachingContentComparison;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** JSON comparison preserves scientific text and structural identities. */
#[Group('actools_lesson')]
final class TeachingContentComparisonTest extends UnitTestCase {

  #[DataProvider('comparisons')]
  public function testCanonicalComparison(string $first, string $second, bool $same): void {
    $this->assertSame($same, TeachingContentComparison::same($first, $second));
  }

  public static function comparisons(): array {
    return [
      'numeric TeX' => ['[{"tex":"10"}]', '[{"tex":"1e1"}]', FALSE],
      'decimal string' => ['[{"tex":"1"}]', '[{"tex":"1.0"}]', FALSE],
      'string and integer' => ['[{"value":"1"}]', '[{"value":1}]', FALSE],
      'boolean and integer' => ['[{"value":true}]', '[{"value":1}]', FALSE],
      'integer and float' => ['[{"value":1}]', '[{"value":1.0}]', FALSE],
      'empty structures' => ['[{}]', '[[]]', FALSE],
      'list order' => ['[{"tex":"x"},{"tex":"y"}]', '[{"tex":"y"},{"tex":"x"}]', FALSE],
      'object property order' => ['[{"id":"a","body":{"value":"10","format":"plain_text"}}]', '[ { "body": { "format":"plain_text", "value":"10" }, "id":"a" } ]', TRUE],
      'Unicode escaping' => ['[{"tex":"\\u03c3"}]', '[{"tex":"σ"}]', TRUE],
      'invalid identical data' => ['not json', 'not json', FALSE],
    ];
  }

}
