<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An organizer of a subject survey, as its snapshot named them.
 *
 * @property string $id
 * @property string $survey_id
 * @property string $user_id
 */
final class SurveyOrganizerModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'surveys_organizers';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'survey_id', 'user_id'];
}
