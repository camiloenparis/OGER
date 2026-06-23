<?php

declare(strict_types=1);

namespace Rules\HR;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/** Règle RH : maximum 6 jours consécutifs de travail */
final class MaxConsecutiveDaysRule implements RuleInterface
{
  private const MAX_CONSECUTIVE = 6;

  public function getName(): string    { return 'rh-max-jours-consecutifs'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $days = array_unique(
      array_map(fn(ShiftProposal $s) => $s->day, $context->weekShifts)
    );
    sort($days);

    // Compter les jours consécutifs se terminant par le jour proposé
    $consecutive = $this->countConsecutiveEndingAt($days, $shift->day);

    if ($consecutive > self::MAX_CONSECUTIVE) {
      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s aurait %d jours consécutifs de travail (maximum : %d).',
          $shift->employeeName, $consecutive, self::MAX_CONSECUTIVE
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    return [];
  }

  private function countConsecutiveEndingAt(array $sortedDays, string $targetDay): int
  {
    // Construire la suite de jours incluant le jour cible
    $allDays = $sortedDays;
    if (!in_array($targetDay, $allDays, true)) {
      $allDays[] = $targetDay;
      sort($allDays);
    }

    $consecutive = 0;
    $prev        = null;
    $streak      = 0;

    foreach ($allDays as $day) {
      if ($prev === null) {
        $streak = 1;
      } else {
        try {
          $diff = (new \DateTime($day))->diff(new \DateTime($prev))->days;
          $streak = ($diff === 1) ? $streak + 1 : 1;
        } catch (\Exception $e) {
          $streak = 1;
        }
      }
      if ($day === $targetDay) {
        $consecutive = $streak;
        break;
      }
      $prev = $day;
    }

    return $consecutive;
  }
}
