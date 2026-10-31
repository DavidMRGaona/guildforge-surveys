@php
    /** @var \Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurveyResults $this */
    $results = $this->results;
    $hiddenTitles = $this->hiddenQuestionTitles();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ trans_choice('surveys::filament.results.responses', $results->responses, ['count' => $results->responses]) }}
        </x-slot>
        <x-slot name="description">
            @if ($results->rate !== null)
                {{ __('surveys::filament.results.rate', ['rate' => \Illuminate\Support\Number::format($results->rate * 100, maxPrecision: 1, locale: app()->getLocale()), 'audience' => $results->audienceSize]) }}
            @else
                {{ __('surveys::filament.results.no_rate') }}
            @endif
        </x-slot>

        @if (! $results->visible)
            <p>{{ __('surveys::filament.results.hidden.'.$results->hiddenReason) }}</p>
        @elseif ($hiddenTitles !== [])
            <p>{{ __('surveys::filament.results.hidden_questions', ['questions' => implode(', ', $hiddenTitles)]) }}</p>
        @endif
    </x-filament::section>

    @foreach ($results->questions as $question)
        @continue($question->hidden)

        <x-filament::section :key="'question-'.$question->questionId">
            <x-slot name="heading">{{ $question->title }}</x-slot>
            <x-slot name="description">
                {{ trans_choice('surveys::filament.results.answered', $question->answered, ['count' => $question->answered]) }}
                @if ($question->mean !== null && ! $question->belowMinimum)
                    · {{ __('surveys::filament.results.mean_median', [
                        'mean' => \Illuminate\Support\Number::format($question->mean, maxPrecision: 2, locale: app()->getLocale()),
                        'median' => \Illuminate\Support\Number::format((float) $question->median, maxPrecision: 1, locale: app()->getLocale()),
                    ]) }}
                @endif
            </x-slot>

            @if ($question->belowMinimum)
                <p>{{ __('surveys::filament.results.question_below_minimum') }}</p>
            @elseif ($question->chartType === \Modules\Surveys\Domain\Enums\ChartType::List)
                @forelse ($question->texts as $text)
                    <p style="padding-block: 0.5rem; border-bottom: 1px solid rgb(127 127 127 / 0.2); white-space: pre-line;">{{ $text }}</p>
                @empty
                    <p>{{ __('surveys::filament.results.no_texts') }}</p>
                @endforelse
            @else
                @livewire(\Modules\Surveys\Filament\Widgets\QuestionResultChart::class, ['surveyId' => $this->surveyId()->value, 'questionId' => $question->questionId], key('chart-'.$question->questionId))

                @if ($question->texts !== [])
                    <p style="margin-top: 1rem; font-weight: 600;">{{ __('surveys::filament.results.other_texts') }}</p>
                    @foreach ($question->texts as $text)
                        <p style="padding-block: 0.5rem; border-bottom: 1px solid rgb(127 127 127 / 0.2); white-space: pre-line;">{{ $text }}</p>
                    @endforeach
                @endif
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
