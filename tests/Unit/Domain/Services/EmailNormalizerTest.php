<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Services\EmailNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'already normal' => ['ana@example.test', 'ana@example.test'];
        yield 'spaces and capitals' => ['  Ana@Example.TEST ', 'ana@example.test'];
        yield 'a tag' => ['ana+encuestas@example.test', 'ana@example.test'];
        yield 'several plus signs' => ['ana+a+b@example.test', 'ana@example.test'];
        yield 'accented letters' => ['ÁNGELA@example.test', 'ángela@example.test'];
        yield 'dots are kept' => ['a.na@example.test', 'a.na@example.test'];
        yield 'no at sign' => [' NoEsUnCorreo ', 'noesuncorreo'];
    }

    #[DataProvider('addresses')]
    public function test_normalizes_an_address(string $email, string $expected): void
    {
        $this->assertSame($expected, EmailNormalizer::normalize($email));
    }
}
