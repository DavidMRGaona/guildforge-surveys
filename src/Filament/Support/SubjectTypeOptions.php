<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;

/**
 * General surveys and the kinds of subject, labelled for the panel. A kind whose module is gone
 * still shows, marked, wherever something stored keeps it.
 */
final class SubjectTypeOptions
{
    /**
     * General first, then the kinds of subject installed, then those of $stored no module offers.
     *
     * @param  iterable<string>  $stored
     * @return array<string, string>
     */
    public static function options(iterable $stored = []): array
    {
        $options = [TemplateUses::GENERAL => (string) __('surveys::filament.subject_types.general')];
        foreach (app(SurveySubjectSourceRegistryInterface::class)->sources() as $type => $source) {
            $options[$type] = $source->sourceLabel();
        }
        foreach ($stored as $type) {
            $options[$type] ??= self::label($type);
        }

        return $options;
    }

    public static function label(string $type): string
    {
        if ($type === TemplateUses::GENERAL) {
            return (string) __('surveys::filament.subject_types.general');
        }

        return app(SurveySubjectSourceRegistryInterface::class)->source($type)?->sourceLabel()
            ?? (string) __('surveys::filament.subject_types.retired', ['type' => $type]);
    }
}
