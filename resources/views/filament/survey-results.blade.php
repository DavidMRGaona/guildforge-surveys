@php
    /** @var \Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurveyResults $this */
    $results = $this->results;
    $hiddenTitles = $this->hiddenQuestionTitles();
    $quiz = $results->visible ? $results->quiz : null;
    $number = static fn (float $value, int $precision = 2): string => (string) \Illuminate\Support\Number::format($value, maxPrecision: $precision, locale: app()->getLocale());
    $fileSize = static fn (int $bytes): string => $bytes >= 1024 * 1024
        ? $number($bytes / (1024 * 1024), 1).' MB'
        : $number(max(1, (int) ceil($bytes / 1024)), 0).' KB';
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

    @if ($quiz !== null)
        <x-filament::section>
            <x-slot name="heading">{{ __('surveys::filament.results.quiz.title') }}</x-slot>
            <x-slot name="description">
                @if ($quiz->belowMinimum)
                    {{ __('surveys::filament.results.quiz.below_minimum') }}
                @elseif ($quiz->mean !== null)
                    {{ __('surveys::filament.results.quiz.mean', ['mean' => $number($quiz->mean), 'max' => $quiz->maxPoints]) }}
                @endif
            </x-slot>

            @if ($quiz->distribution !== [])
                <p style="font-weight: 600;">{{ __('surveys::filament.results.quiz.distribution') }}</p>
                <div style="overflow-x: auto; margin-top: 0.5rem;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.3);">
                                <th scope="col" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 600;">{{ __('surveys::filament.results.quiz.score') }}</th>
                                <th scope="col" style="padding: 0.5rem 0 0.5rem 0.75rem; text-align: end; font-weight: 600;">{{ __('surveys::filament.results.quiz.responses') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quiz->distribution as $count)
                                <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.2);">
                                    <th scope="row" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 400; font-variant-numeric: tabular-nums;">{{ $count->points }}</th>
                                    <td style="padding: 0.5rem 0 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $count->responses }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($quiz->leaderboard !== null && $quiz->leaderboard !== [])
                <p style="margin-top: 1.5rem; font-weight: 600;">{{ __('surveys::filament.results.quiz.leaderboard') }}</p>
                <div style="overflow-x: auto; margin-top: 0.5rem;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.3);">
                                <th scope="col" style="padding: 0.5rem 0.75rem 0.5rem 0; text-align: start; font-weight: 600;">{{ __('surveys::filament.results.quiz.rank') }}</th>
                                <th scope="col" style="padding: 0.5rem 0.75rem; text-align: start; font-weight: 600;">{{ __('surveys::filament.results.quiz.person') }}</th>
                                <th scope="col" style="padding: 0.5rem 0 0.5rem 0.75rem; text-align: end; font-weight: 600;">{{ __('surveys::filament.results.quiz.points') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quiz->leaderboard as $standing)
                                <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.2);">
                                    <td style="padding: 0.5rem 0.75rem 0.5rem 0; font-variant-numeric: tabular-nums;">{{ __('surveys::filament.results.place', ['place' => $standing->rank]) }}</td>
                                    <th scope="row" style="padding: 0.5rem 0.75rem; text-align: start; font-weight: 400;">{{ $standing->name }}</th>
                                    <td style="padding: 0.5rem 0 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ $standing->points }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif

    @foreach ($results->questions as $question)
        @continue($question->hidden)

        <x-filament::section :key="'question-'.$question->questionId">
            <x-slot name="heading">{{ $question->title }}</x-slot>
            <x-slot name="description">
                {{ trans_choice('surveys::filament.results.answered', $question->answered, ['count' => $question->answered]) }}
                @if ($question->reached !== null)
                    · {{ __('surveys::filament.results.reached', ['reached' => $question->reached, 'responses' => $results->responses]) }}
                @endif
                @if ($question->quiz !== null && ! $question->belowMinimum)
                    · {{ trans_choice('surveys::filament.results.quiz.question_points', $question->quiz->points, ['count' => $question->quiz->points]) }}
                    @if ($question->quiz->rate !== null)
                        · {{ __('surveys::filament.results.quiz.correct_rate', ['rate' => $number($question->quiz->rate * 100, 0), 'correct' => $question->quiz->correct, 'answered' => $question->answered]) }}
                    @endif
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
            @elseif ($question->type === \Modules\Surveys\Domain\Enums\QuestionType::File)
                {{-- Files are never charted: each one opens through a signed link, images with a preview --}}
                <p style="margin-bottom: 0.75rem; font-size: 0.875rem; opacity: 0.75;">{{ __('surveys::filament.results.files_private') }}</p>
                @forelse ($question->files as $file)
                    @php
                        $name = \Modules\Surveys\Infrastructure\Files\AnswerFileLinks::downloadName($file);
                        $preview = \Modules\Surveys\Domain\Enums\FileKind::forContentType($file->contentType) === \Modules\Surveys\Domain\Enums\FileKind::Image
                            ? \Modules\Surveys\Infrastructure\Files\AnswerFileLinks::forPage($file, inline: true)
                            : null;
                    @endphp
                    <div data-answer-file="{{ $file->id }}" style="display: flex; align-items: center; gap: 0.75rem; padding-block: 0.5rem; border-bottom: 1px solid rgb(127 127 127 / 0.2);">
                        @if ($preview !== null)
                            <a href="{{ $preview }}" target="_blank" rel="noopener" style="flex-shrink: 0;">
                                <img src="{{ $preview }}" alt="{{ __('surveys::filament.results.file_preview', ['name' => $name]) }}" loading="lazy" style="width: 4rem; height: 4rem; object-fit: cover; border-radius: 0.375rem;">
                            </a>
                        @endif
                        <div style="min-width: 0;">
                            <a href="{{ \Modules\Surveys\Infrastructure\Files\AnswerFileLinks::forPage($file) }}" style="font-weight: 500; text-decoration: underline; overflow-wrap: anywhere;">{{ $name }}</a>
                            <span style="display: block; font-size: 0.875rem; opacity: 0.75;">{{ $fileSize($file->size) }}</span>
                        </div>
                    </div>
                @empty
                    <p>{{ __('surveys::filament.results.no_files') }}</p>
                @endforelse
            @elseif ($question->chartType === \Modules\Surveys\Domain\Enums\ChartType::List)
                @forelse ($question->texts as $text)
                    <p style="padding-block: 0.5rem; border-bottom: 1px solid rgb(127 127 127 / 0.2); white-space: pre-line;">{{ $text }}</p>
                @empty
                    <p>{{ __('surveys::filament.results.no_texts') }}</p>
                @endforelse
            @else
                @livewire(\Modules\Surveys\Filament\Widgets\QuestionResultChart::class, ['surveyId' => $this->surveyId()->value, 'questionId' => $question->questionId], key('chart-'.$question->questionId))

                @if ($question->quiz !== null)
                    @php
                        $correctLabels = array_values(array_map(
                            static fn ($bucket): string => (string) $bucket->label,
                            array_filter($question->buckets, static fn ($bucket): bool => in_array($bucket->key, $question->quiz->correctOptionIds, true)),
                        ));
                    @endphp
                    <p style="margin-top: 1rem; font-weight: 600;">{{ trans_choice('surveys::filament.results.quiz.correct_answer', count($correctLabels), ['answers' => \Illuminate\Support\Arr::join($correctLabels, ', ', ' '.__('surveys::filament.results.quiz.and').' ')]) }}</p>
                @endif

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
