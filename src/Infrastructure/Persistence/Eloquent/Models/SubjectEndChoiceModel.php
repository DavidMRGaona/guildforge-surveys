<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The survey chosen for one subject's end instead of its type's template.
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $mode template | none
 * @property string|null $template_id
 * @property string|null $chosen_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SubjectEndChoiceModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public const string MODE_TEMPLATE = 'template';

    public const string MODE_NONE = 'none';

    protected $table = 'surveys_subject_end_choices';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'subject_type', 'subject_id', 'mode', 'template_id', 'chosen_by'];
}
