<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Concerns\UsesRandomUuids;

/**
 * One value of a response (see AnswerValue): results are counted from these rows.
 *
 * @property string $id
 * @property string $response_id
 * @property string $question_id
 * @property string|null $option_id
 * @property string|null $row_id
 * @property string|null $text_value
 * @property int|null $number_value
 */
final class SurveyAnswerModel extends Model
{
    use UsesRandomUuids;

    public $timestamps = false;

    protected $table = 'surveys_answers';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['response_id', 'question_id', 'option_id', 'row_id', 'text_value', 'number_value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number_value' => 'integer'];
    }
}
