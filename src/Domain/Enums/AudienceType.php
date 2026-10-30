<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * Who a survey goes to. General surveys pick everyone, association members or some roles;
 * a subject's survey goes to the audience its source returns.
 */
enum AudienceType: string
{
    case AllUsers = 'all_users';
    case AssociationMembers = 'association_members';
    case Roles = 'roles';
    case Subject = 'subject';
}
