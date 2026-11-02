<?php

declare(strict_types=1);

namespace Modules\Surveys\Console\Commands;

use Illuminate\Console\Command;
use Modules\Surveys\Application\Contracts\AnswerFileStoreInterface;

final class PruneAnswerFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'surveys:prune-files';

    /**
     * @var string
     */
    protected $description = 'Remove answer files no response points at, once they are a day old';

    public function handle(AnswerFileStoreInterface $files): int
    {
        $pruned = $files->pruneOrphans(now()->subDay()->toDateTimeImmutable());

        $this->info("Removed {$pruned} file(s).");

        return self::SUCCESS;
    }
}
