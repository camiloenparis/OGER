<?php

declare(strict_types=1);

namespace Rules\HR;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/**
 * Règle RH : les apprentis ne peuvent pas commencer avant 6h (mineurs) ou 4h (majeurs).
 * Source : regles-planning.instructions.md
 */
final class ApprenticeStartTimeRule implements RuleInterface
{
  public function getName(): string    { return 'rh-heure-debut-apprenti'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $employee = $context->employee;

    if (!$employee->isApprentice) {
      return [];
    }

    $startMinutes = $shift->getStartMinutes();

    if ($employee->isMinor() && $startMinutes < 6 * 60) {
      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s est apprenti(e) mineur(e) et ne peut pas commencer avant 6h00 (shift prévu à %02d:%02d).',
          $shift->employeeName, $shift->startHour, $shift->startMinute
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    if (!$employee->isMinor() && $startMinutes < 4 * 60) {
      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s est apprenti(e) majeur(e) et ne peut pas commencer avant 4h00 (shift prévu à %02d:%02d).',
          $shift->employeeName, $shift->startHour, $shift->startMinute
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    return [];
  }
}
