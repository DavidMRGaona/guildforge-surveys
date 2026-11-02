<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file sent as an answer (see StoredAnswerFile). Its id is the answer's value and a random
 * one, so listing files by id says nothing about who sent which.
 *
 * @property string $id
 * @property string $survey_id
 * @property string $response_id
 * @property string $question_id
 * @property string $path
 * @property string $content_type
 * @property int $size
 * @property string|null $original_name
 */
final class SurveyAnswerFileModel extends Model
{
    public const ?string UPDATED_AT = null;

    protected $table = 'surveys_answer_files';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'survey_id', 'response_id', 'question_id', 'path', 'content_type', 'size', 'original_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
