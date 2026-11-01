<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * What a template is meant for: general surveys and kinds of subject (their source type, e.g.
 * 'game-table'). None means it is meant for anything. A kind whose module is gone is kept.
 */
final readonly class TemplateUses
{
    public const string GENERAL = 'general';

    private const string PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** @var list<string> */
    public array $values;

    /**
     * @param  list<string>  $values
     *
     * @throws InvalidArgumentException when a value is not a kebab-case type
     */
    public function __construct(array $values)
    {
        foreach ($values as $value) {
            if (preg_match(self::PATTERN, $value) !== 1) {
                throw new InvalidArgumentException("Invalid template use: {$value}");
            }
        }

        $this->values = array_values(array_unique($values));
    }

    public function isUnspecified(): bool
    {
        return $this->values === [];
    }
}
