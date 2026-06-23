<?php

declare(strict_types=1);

namespace Rules\HR;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/** Règle RH : au moins 11h de repos entre deux shifts */
final class MinRestBetweenShiftsRule implements RuleInterface
{
  private const MIN_REST_MINUTES = 11 * 60; // 660 min

  public function getName(): string    { return 'rh-repos-minimum-11h'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $sortedShifts = $context->getWeekShiftsSorted();

    // Trouver le shift précédent (le plus récent avant ce jour)
    $prevShift = null;
    foreach ($sortedShifts as $s) {
      if ($s->day < $shift->day) {
        $prevShift = $s;
      }
    }

    if ($prevShift === null) {
      return [];
    }

    try {
      $prevEndDate = new \DateTime($prevShift->day);
      $curStartDate = new \DateTime($shift->day);
      $daysDiff = (int) $prevEndDate->diff($curStartDate)->days;

      // Calculer le gap en minutes entre la fin du shift précédent et le début du nouveau
      $gapMinutes = $daysDiff * 24 * 60
        + $shift->getStartMinutes()
        - $prevShift->getEndMinutes();

      if ($gapMinutes < self::MIN_REST_MINUTES) {
        return [new Violation(
          ruleName:     $this->getName(),
          priority:     $this->getPriority(),
          message:      sprintf(
            '%s n\'a que %dh%02d de repos entre le %s (fin %02d:%02d) et le %s (début %02d:%02d). Minimum requis : 11h.',
            $shift->employeeName,
            intdiv($gapMinutes, 60), $gapMinutes % 60,
            $prevShift->day, $prevShift->endHour, $prevShift->endMinute,
            $shift->day, $shift->startHour, $shift->startMinute,
          ),
          employeeName: $shift->employeeName,
          day:          $shift->day,
        )];
      }
    } catch (\Exception $e) {
      // Ne pas bloquer si la date est invalide
    }

    return [];
  }
}
