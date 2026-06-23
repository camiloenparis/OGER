<?php

declare(strict_types=1);

namespace Rules\HR;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/** Règle RH : un employé ne peut pas travailler plus de 10 heures par jour */
final class MaxDailyHoursRule implements RuleInterface
{
  private const MAX_HOURS = 10;

  public function getName(): string    { return 'rh-max-heures-jour'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $workHours = $shift->getWorkMinutes() / 60;

    if ($workHours > self::MAX_HOURS) {
      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s travaillerait %.1fh le %s (maximum autorisé : %dh).',
          $shift->employeeName, $workHours, $shift->day, self::MAX_HOURS
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    return [];
  }
}
