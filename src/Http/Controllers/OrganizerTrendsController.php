<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\Services\TrendsService;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Http\Support\TrendsPayload;

/**
 * An organizer's trends on the site: only the templates and surveys whose snapshot names them,
 * and no filter or comparison by organizer.
 */
final class OrganizerTrendsController extends Controller
{
    public function __construct(
        private readonly TrendsService $trends,
        private readonly SurveySettingsInterface $settings,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function show(Request $request): Response
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $templates = $this->trends->templatesFor($userId);
        $ids = array_column($templates, 'id');
        $requested = $request->query('template');
        $templateId = is_string($requested) && in_array($requested, $ids, true) ? $requested : ($ids[0] ?? null);
        $filters = TrendFilters::fromInput($request->query())->withoutOrganizer();

        $template = $templateId === null ? null : SurveyTemplateId::fromString($templateId);
        $options = $template instanceof SurveyTemplateId ? $this->trends->filterOptions($template, $userId) : null;
        $input = $filters->toInput();
        unset($input['organizer']);

        return Inertia::render('Surveys/Manage/Trends', [
            'templates' => $templates,
            'templateId' => $templateId,
            'filters' => $input,
            'options' => [
                'subjectTypes' => $options->subjectTypes ?? [],
                'gameSystems' => $options->gameSystems ?? [],
            ],
            'trends' => $template instanceof SurveyTemplateId ? TrendsPayload::from($this->trends->forOrganizer($template, $userId, $filters)) : null,
            'minimum' => $this->settings->minResponses(),
            'url' => route('surveys.manage.trends'),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('surveys::messages.manage.trends_title'),
                noindex: true,
            ))->toArray(),
        ]);
    }
}
