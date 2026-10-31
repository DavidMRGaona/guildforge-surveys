<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Exceptions;

use DomainException;

/**
 * A change the survey's state does not allow, with the editor field it is about and the key of
 * its message in surveys::messages.state_errors.
 */
final class OrganizerChangeRejectedException extends DomainException
{
    public function __construct(
        public readonly string $field,
        public readonly string $messageKey,
    ) {
        parent::__construct("The change of '{$field}' is not allowed ({$messageKey}).");
    }
}
