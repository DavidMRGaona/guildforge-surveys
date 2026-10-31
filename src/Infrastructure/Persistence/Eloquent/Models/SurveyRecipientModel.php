<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone a survey was sent to, fixed when it opened: an account or a guest. An address that
 * asked for a link on the verified public link is one too, of its own source: it never joins
 * the audience or gets reminders, and its reference (a keyed hash of the address) keeps it to
 * one link. A guest's email address and link token are encrypted and cleared when the survey
 * closes; lookups use the token's hash.
 *
 * @property string $id
 * @property string $survey_id
 * @property string|null $user_id
 * @property string|null $guest_reference
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $token
 * @property string|null $token_hash
 * @property string $source
 * @property Carbon|null $notified_at
 * @property Carbon|null $reminded_at
 */
final class SurveyRecipientModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public const string SOURCE_AUDIENCE = 'audience';

    public const string SOURCE_PUBLIC_LINK = 'public_link';

    public $timestamps = false;

    protected $table = 'surveys_recipients';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'survey_id', 'user_id', 'guest_reference', 'guest_name', 'guest_email', 'token', 'token_hash', 'source', 'notified_at', 'reminded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guest_email' => 'encrypted',
            'token' => 'encrypted',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }
}
