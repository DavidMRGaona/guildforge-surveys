<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kind
 * @property string $title
 * @property string|null $description
 * @property array<mixed> $definition
 * @property string $status
 * @property bool $anonymous
 * @property array<mixed> $results_settings
 * @property string $audience_type
 * @property list<string> $audience_roles
 * @property bool $public_link_enabled
 * @property string|null $public_token
 * @property string|null $public_token_hash
 * @property string|null $template_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<mixed>|null $subject_snapshot
 * @property int $audience_size
 * @property int|null $reminder_hours_before
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property Carbon|null $closed_at
 * @property string|null $created_by
 * @property int|null $duration_days
 * @property bool $unreviewed_changes
 * @property string|null $last_changed_by
 * @property string|null $rejection_reason
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_for_review_at
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $scheduled_send_at
 * @property Carbon|null $dismissed_at
 * @property string|null $subject_end_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SurveyModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'surveys_surveys';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'kind',
        'title',
        'description',
        'definition',
        'status',
        'anonymous',
        'results_settings',
        'audience_type',
        'audience_roles',
        'public_link_enabled',
        'public_token',
        'public_token_hash',
        'template_id',
        'subject_type',
        'subject_id',
        'subject_snapshot',
        'audience_size',
        'reminder_hours_before',
        'reminder_sent_at',
        'opens_at',
        'closes_at',
        'closed_at',
        'created_by',
        'duration_days',
        'unreviewed_changes',
        'last_changed_by',
        'rejection_reason',
        'submitted_by',
        'submitted_for_review_at',
        'reviewed_by',
        'reviewed_at',
        'scheduled_send_at',
        'dismissed_at',
        'subject_end_key',
    ];

    /**
     * @return HasMany<SurveyResponseModel, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponseModel::class, 'survey_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'anonymous' => 'boolean',
            'results_settings' => 'array',
            'audience_roles' => 'array',
            'public_link_enabled' => 'boolean',
            'public_token' => 'encrypted',
            'subject_snapshot' => 'array',
            'audience_size' => 'integer',
            'reminder_hours_before' => 'integer',
            'reminder_sent_at' => 'datetime',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
            'duration_days' => 'integer',
            'unreviewed_changes' => 'boolean',
            'submitted_for_review_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'scheduled_send_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }
}
