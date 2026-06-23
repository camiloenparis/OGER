<?php

declare(strict_types=1);

namespace Rules\Preferences;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/**
 * Règle Préférence (HARD) : plafond de durée journalière pour certains employés.
 * Ex : Amel Zeghad (handicap) ne peut pas travailler plus de 6h/jour.
 *
 * Config dans : src/Config/EmployeePreferences.php  →  clé 'max_daily_hours'
 */
final class MaxDailyHoursCapRule implements RuleInterface
{
  public function getName(): string    { return 'pref-max-heures-jour'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $prefs = $context->getMyPrefs();

    if (!isset($prefs['max_daily_hours'])) {
      return [];
    }

    $cap       = (float) $prefs['max_daily_hours'];
    $workHours = $shift->getWorkMinutes() / 60;

    if ($workHours > $cap) {
      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s ne peut pas travailler plus de %.0fh/jour (shift proposé : %.2fh).',
          $shift->employeeName, $cap, $workHours
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    return [];
  }
}
