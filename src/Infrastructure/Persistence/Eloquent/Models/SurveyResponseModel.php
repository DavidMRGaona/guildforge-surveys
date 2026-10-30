<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Concerns\UsesRandomUuids;

/**
 * One response. It keeps the day it was sent and no time; the person (account or guest
 * display name) only on named surveys.
 *
 * @property string $id
 * @property string $survey_id
 * @property Carbon $submitted_on
 * @property string|null $user_id
 * @property string|null $guest_name
 */
final class SurveyResponseModel extends Model
{
    use UsesRandomUuids;

    public $timestamps = false;

    protected $table = 'surveys_responses';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['survey_id', 'submitted_on', 'user_id', 'guest_name'];

    /**
     * @return HasMany<SurveyAnswerModel, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswerModel::class, 'response_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_on' => 'date'];
    }
}
