<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;

/**
 * Form templates for a select: those meant for the use first, the rest under «Otras», so a
 * template chosen before it was marked for something else is still there. One flat list when
 * either group would be empty.
 */
final class TemplateOptions
{
    /**
     * @return array<string, string|array<string, string>> template id => name, or heading => those
     */
    public static function for(string $use): array
    {
        ['matching' => $matching, 'others' => $others] = app(SurveyTemplateRepositoryInterface::class)->formNamesFor($use);

        if ($matching === [] || $others === []) {
            return $matching + $others;
        }

        $heading = $use === TemplateUses::GENERAL ? 'for_general' : 'for_type';

        return [
            (string) __("surveys::filament.template_options.{$heading}") => $matching,
            (string) __('surveys::filament.template_options.others') => $others,
        ];
    }
}
