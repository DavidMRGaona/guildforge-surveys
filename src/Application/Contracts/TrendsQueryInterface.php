<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

/**
 * Aggregates over the answers of many surveys, kept apart by survey: the trends service
 * decides which surveys count for each question and sums them by period and series.
 */
interface TrendsQueryInterface
{
    /**
     * @param  list<string>  $surveyIds
     * @return array<string, int> survey id => responses
     */
    public function responseCounts(array $surveyIds): array;

    /**
     * Responses that came with a participation (an account, a guest or a verified address):
     * the only ones the minimum counts.
     *
     * @param  list<string>  $surveyIds
     * @return array<string, int> survey id => verified responses
     */
    public function verifiedResponseCounts(array $surveyIds): array;

    /**
     * Responses that answered each question. "verified" is what the minimum counts.
     *
     * @param  list<string>  $surveyIds
     * @param  list<string>  $questionIds
     * @return array<string, array<string, array{all: int, verified: int}>> survey id => question id => counts
     */
    public function answeredCounts(array $surveyIds, array $questionIds): array;

    /**
     * @param  list<string>  $surveyIds
     * @param  list<string>  $questionIds
     * @return array<string, array<string, array<string, int>>> survey id => question id => option id (Submission::OTHER for "Other") => answers
     */
    public function optionCounts(array $surveyIds, array $questionIds): array;

    /**
     * @param  list<string>  $surveyIds
     * @param  list<string>  $questionIds
     * @return array<string, array<string, array<int, int>>> survey id => question id => value => answers
     */
    public function numberCounts(array $surveyIds, array $questionIds): array;

    /**
     * @param  list<string>  $surveyIds
     * @param  list<string>  $questionIds
     * @return array<string, array<string, array<string, array<string, int>>>> survey id => question id => row id => column id => answers
     */
    public function cellCounts(array $surveyIds, array $questionIds): array;

    /**
     * Current names of these accounts (anonymized ones included); unknown ids are left out.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function userNames(array $userIds): array;

    /**
     * Templates with at least one survey that has opened, or only those of surveys this person
     * organized.
     *
     * @return list<string>
     */
    public function templateIds(?string $organizerId = null): array;
}
