<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Concerns\UsesRandomUuids;

/**
 * "Already answered": an account or a guest link hash per survey. It keeps no date and
 * nothing pointing at the response, so an anonymous response cannot be traced back.
 *
 * @property string $id
 * @property string $survey_id
 * @property string|null $user_id
 * @property string|null $token_hash
 */
final class SurveyParticipationModel extends Model
{
    use UsesRandomUuids;

    public $timestamps = false;

    protected $table = 'surveys_participations';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['survey_id', 'user_id', 'token_hash'];
}
