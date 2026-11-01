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
                @if ($question->reached !== null)
                    · {{ __('surveys::filament.results.reached', ['reached' => $question->reached, 'responses' => $results->responses]) }}
                @endif
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

                @if ($question->availability !== null)
                    <p style="margin-top: 1rem; font-size: 0.875rem; opacity: 0.8;">{{ __('surveys::filament.results.availability.help') }}</p>
                    <div style="overflow-x: auto; margin-top: 0.5rem;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.3);">
                                    <th scope="col" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 600;">{{ __('surveys::filament.results.availability.slot') }}</th>
                                    @foreach (['yes', 'if_needed', 'no'] as $answer)
                                        <th scope="col" style="padding: 0.5rem 0.75rem; text-align: end; font-weight: 600;">{{ __('surveys::messages.availability.answers.'.$answer) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($question->availability->slots as $slot)
                                    <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.2);">
                                        <th scope="row" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 400;">
                                            {{ mb_ucfirst($slot->label) }}
                                            @if ($question->availability->chosenSlotId === $slot->slotId)
                                                <x-filament::badge color="success" style="display: inline-flex; margin-inline-start: 0.5rem;">{{ __('surveys::filament.results.availability.chosen') }}</x-filament::badge>
                                            @elseif ($slot->best)
                                                <x-filament::badge color="info" style="display: inline-flex; margin-inline-start: 0.5rem;">{{ __('surveys::filament.results.availability.best') }}</x-filament::badge>
                                            @endif
                                            @foreach (['yes' => $slot->yesNames, 'if_needed' => $slot->ifNeededNames] as $answer => $names)
                                                @if ($names !== [])
                                                    <span style="display: block; font-size: 0.75rem; opacity: 0.75;">{{ __('surveys::messages.availability.answers.'.$answer) }}: {{ implode(', ', $names) }}</span>
                                                @endif
                                            @endforeach
                                        </th>
                                        <td style="padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $slot->yes }}</td>
                                        <td style="padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $slot->ifNeeded }}</td>
                                        <td style="padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $slot->no }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($question->ranking !== null)
                    <p style="margin-top: 1rem; font-size: 0.875rem; opacity: 0.8;">{{ __('surveys::filament.results.ranking.help', ['positions' => $question->ranking->positions]) }}</p>
                    <div style="overflow-x: auto; margin-top: 0.5rem;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.3);">
                                    <th scope="col" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 600;">{{ __('surveys::filament.results.ranking.option') }}</th>
                                    <th scope="col" style="padding: 0.5rem 0.75rem; text-align: end; font-weight: 600;">{{ __('surveys::filament.results.ranking.points') }}</th>
                                    <th scope="col" style="padding: 0.5rem 0 0.5rem 0.75rem; text-align: end; font-weight: 600;">{{ __('surveys::filament.results.ranking.mean_place') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($question->ranking->options as $option)
                                    <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.2);">
                                        <th scope="row" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 400;">{{ $option->label }}</th>
                                        <td style="padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $option->points }}</td>
                                        <td style="padding: 0.5rem 0 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $option->meanPosition === null ? '—' : \Illuminate\Support\Number::format($option->meanPosition, maxPrecision: 2, locale: app()->getLocale()) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

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
