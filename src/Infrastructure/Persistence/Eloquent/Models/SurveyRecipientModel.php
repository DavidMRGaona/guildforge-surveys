<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone a survey was sent to, fixed when it opened: an account or a guest. A guest's
 * email address is encrypted and cleared when the survey closes; their link is only kept
 * as a hash.
 *
 * @property string $id
 * @property string $survey_id
 * @property string|null $user_id
 * @property string|null $guest_reference
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $token_hash
 * @property Carbon|null $notified_at
 * @property Carbon|null $reminded_at
 */
final class SurveyRecipientModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public $timestamps = false;

    protected $table = 'surveys_recipients';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'survey_id', 'user_id', 'guest_reference', 'guest_name', 'guest_email', 'token_hash', 'notified_at', 'reminded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guest_email' => 'encrypted',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }
}
