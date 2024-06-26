<?php

namespace go1\util\enrolment;

use Doctrine\DBAL\Connection;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LoTypes;
use go1\util\plan\PlanStatuses;
use InvalidArgumentException;
use stdClass;

class EnrolmentStatuses
{
    # Pre-enrolment statuses
    # ---------------------
    # const PENDING                  = -1; # will be removed soon
    # const ASSIGNED                 = -3; # Someone added this for you to do
    public const PENDING_REVIEW           = -1;
    public const ENQUIRED                 = -4; # @deprecated: We will use plan object for this this kind of purpose.
    public const MANUAL_COMPLETE          = -5;
    public const MANUAL_COMPLETE_VERIFIED = -6;
    public const MANUAL_COMPLETE_REJECTED = -7;

    # Enrolment statuses
    # ---------------------
    public const NOT_STARTED = 'not-started'; # you have enrolled but not yet opened the course
    public const IN_PROGRESS = 'in-progress'; # you are learning the LO.
    public const PENDING     = 'pending';     # you have enrolled but the enrolment need to be reviewed or blocked by other enrolment
    public const COMPLETED   = 'completed';   # you get this state when you finish the course
    public const EXPIRED     = 'expired';     # your enrolment was completed, but it's expired.

    # Enrolment pass
    # ---------------------
    public const PASSED = 1;
    public const FAILED = 0;

    // Numeric values for the statuses. Being used in ES.
    public const I_NOT_STARTED = -20;
    public const I_PENDING     = -10;
    public const I_IN_PROGRESS = 10;
    public const I_EXPIRED     = 20;
    public const I_COMPLETED   = 100;

    public const PERCENTAGE = 'percentage';

    /**
     * All available values that user can input.
     * Expired is only set by our background logic.
     * @return string[]
     */
    public static function all(): array
    {
        return [self::NOT_STARTED, self::IN_PROGRESS, self::PENDING, self::COMPLETED];
    }

    public static function toNumeric(string $status): int
    {
        switch ($status) {
            case self::NOT_STARTED:
                return self::I_NOT_STARTED;

            case self::PENDING:
                return self::I_PENDING;

            case PlanStatuses::S_ASSIGNED:
                return PlanStatuses::ASSIGNED;

            case self::IN_PROGRESS:
                return self::I_IN_PROGRESS;

            case self::EXPIRED:
                return self::I_EXPIRED;

            case self::COMPLETED:
                return self::I_COMPLETED;

            default:
                throw new InvalidArgumentException('Unknown enrolment status: ' . $status);
        }
    }

    public static function toString(int $status): string
    {
        switch ($status) {
            case self::I_NOT_STARTED:
                return self::NOT_STARTED;

            case self::I_PENDING:
                return self::PENDING;

            case self::I_IN_PROGRESS:
                return self::IN_PROGRESS;

            case self::I_EXPIRED:
                return self::EXPIRED;

            case self::I_COMPLETED:
                return self::COMPLETED;

            case PlanStatuses::ASSIGNED:
                return PlanStatuses::S_ASSIGNED;

            default:
                throw new InvalidArgumentException('Unknown enrolment status: ' . $status);
        }
    }

    public static function defaultStatus(Connection $db, int $userId, stdClass $lo, string $input = self::IN_PROGRESS): string
    {
        // Mark status is "pending" enrolment If a user enrolls to a dependency module.
        if (LoTypes::MODULE === $lo->type) {
            $query = $db->executeQuery('SELECT target_id FROM gc_ro WHERE source_id = ? AND type = ?', [$lo->id, EdgeTypes::HAS_MODULE_DEPENDENCY]);

            while ($dependencyModuleId = $query->fetchColumn()) {
                if (!$enrolmentIds = EnrolmentHelper::enrolmentIdsByLoAndUser($db, $dependencyModuleId, $userId)) {
                    return self::PENDING;
                } else {
                    $enrolmentId = reset($enrolmentIds);
                    $enrolment = EnrolmentHelper::load($db, $enrolmentId);
                    if (EnrolmentStatuses::COMPLETED != $enrolment->status) {
                        return self::PENDING;
                    }
                }
            }
        }

        // GO1P-6926: If there's a scheduling, user can't start the LO instantly.
        $schedule = 'SELECT 1 FROM gc_ro WHERE type = ? AND source_id = ? AND target_id > ?';
        $schedule = $db->fetchColumn($schedule, [EdgeTypes::PUBLISH_ENROLMENT_LO_START_BASE, $lo->id, time()]);
        if ($schedule) {
            return self::PENDING;
        }

        return $input;
    }
}
