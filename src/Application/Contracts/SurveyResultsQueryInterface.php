<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Aggregates over a survey's stored answers. Each method takes the questions it should look at.
 */
interface SurveyResultsQueryInterface
{
    public function responseCount(SurveyId $surveyId): int;

    /**
     * Responses that came with a participation (an account, a guest or a verified address): the
     * only ones that count towards the minimum of an anonymous survey.
     */
    public function verifiedResponseCount(SurveyId $surveyId): int;

    /**
     * @param  list<string>  $questionIds
     * @return array<string, int> question id => responses that answered it
     */
    public function answeredCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * @param  list<string>  $questionIds
     * @return array<string, int> question id => verified responses that answered it
     */
    public function verifiedAnsweredCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * @param  list<string>  $questionIds
     * @return array<string, array<string, int>> question id => option id (Submission::OTHER for "Other") => answers
     */
    public function optionCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * @param  list<string>  $questionIds
     * @return array<string, array<string, array<string, int>>> question id => row id => column id => answers
     */
    public function cellCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * How often each place of a ranking went to each option.
     *
     * @param  list<string>  $questionIds
     * @return array<string, array<string, array<int, int>>> question id => option id => place => answers
     */
    public function positionCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * @param  list<string>  $questionIds
     * @return array<string, array<int, int>> question id => value => answers
     */
    public function numberCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * @param  list<string>  $questionIds
     * @return array<string, array<string, int>> question id => text value => answers
     */
    public function textCounts(SurveyId $surveyId, array $questionIds): array;

    /**
     * Free text, and the text of "Other" on choice questions, ordered by the answer's random id
     * so the list keeps no trace of who answered when.
     *
     * @param  list<string>  $questionIds
     * @return array<string, list<string>>
     */
    public function texts(SurveyId $surveyId, array $questionIds): array;

    /**
     * Who chose each option, by name: the account's current name or the guest's. Only meaningful
     * on named surveys, where responses keep the person.
     *
     * @param  list<string>  $questionIds
     * @return array<string, array<string, list<string>>> question id => option id => names, sorted
     */
    public function voters(SurveyId $surveyId, array $questionIds): array;

    /**
     * Every response with its values for the given questions (none when it left them all
     * unanswered): what SectionNavigator needs to know the sections each response reached.
     *
     * @param  list<string>  $questionIds
     * @return list<array{verified: bool, values: list<AnswerValue>}>
     */
    public function responseValues(SurveyId $surveyId, array $questionIds): array;
}
