<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string $kind
 * @property array<mixed> $definition
 * @property string|null $created_by
 * @property string|null $catalog_key
 * @property list<string>|null $uses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SurveyTemplateModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'surveys_templates';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'name', 'description', 'kind', 'definition', 'created_by', 'catalog_key', 'uses'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['definition' => 'array', 'uses' => 'array'];
    }
}
