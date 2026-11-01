<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
use PHPUnit\Framework\TestCase;

final class TemplateUsesTest extends TestCase
{
    public function test_it_keeps_each_use_once_in_the_order_given(): void
    {
        $uses = new TemplateUses(['game-table', TemplateUses::GENERAL, 'game-table']);

        $this->assertSame(['game-table', 'general'], $uses->values);
    }

    public function test_no_use_means_it_is_meant_for_anything(): void
    {
        $this->assertTrue((new TemplateUses([]))->isUnspecified());
        $this->assertFalse((new TemplateUses(['tournament']))->isUnspecified());
    }

    public function test_a_use_is_general_or_a_kind_of_subject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemplateUses(['Game Table']);
    }
}
