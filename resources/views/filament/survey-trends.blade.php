@php
    /** @var \Modules\Surveys\Filament\Pages\SurveyTrendsPage $this */
    $trends = $this->trends;
    $filters = $this->filterInput();
    $cut = md5(json_encode($filters, JSON_THROW_ON_ERROR));
@endphp

<x-filament-panels::page>
    @if ($trends === null)
        <x-filament::section>
            <p>{{ __('surveys::filament.trends.no_templates') }}</p>
        </x-filament::section>
    @else
        {{ $this->form }}

        <x-filament::section>
            <x-slot name="heading">
                {{ trans_choice('surveys::filament.trends.surveys', $trends->surveys, ['count' => $trends->surveys]) }}
                · {{ trans_choice('surveys::filament.trends.responses', $trends->responses, ['count' => $trends->responses]) }}
            </x-slot>
            <x-slot name="description">{{ __('surveys::filament.trends.rule') }}</x-slot>

            @unless ($trends->visible)
                <p>{{ __('surveys::filament.trends.below_minimum') }}</p>
            @endunless
        </x-filament::section>

        @foreach ($trends->questions as $question)
            <x-filament::section :key="'trend-'.$question->key">
                <x-slot name="heading">{{ $question->title }}</x-slot>
                <x-slot name="description">{{ __('surveys::filament.trends.measures.'.$question->measure) }}</x-slot>

                @if ($question->measure === \Modules\Surveys\Application\DTOs\Trends\QuestionTrendDTO::NONE)
                    <p>{{ __('surveys::filament.trends.no_trend') }}</p>
                @elseif ($question->series === [])
                    <p>{{ __('surveys::filament.trends.no_data') }}</p>
                @else
                    @livewire(\Modules\Surveys\Filament\Widgets\QuestionTrendChart::class, ['templateId' => $trends->templateId, 'questionKey' => $question->key, 'filters' => $filters], key('trend-chart-'.$question->key.'-'.$cut))

                    <p style="margin-top: 0.75rem; font-size: 0.875rem; opacity: 0.8;">{{ __('surveys::filament.trends.gaps', ['minimum' => $this->minimum()]) }}</p>
                @endif
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
