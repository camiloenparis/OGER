<?php

declare(strict_types=1);

namespace Rules\Preferences;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/**
 * Règle Préférence (HARD) : heure de début imposée ou minimale.
 *
 * Deux modes configurables dans src/Config/EmployeePreferences.php :
 *
 *   'fixed_start_minutes' => [120, 180]
 *     → L'employé commence EXCLUSIVEMENT à 2h ou 3h (ex: Carlos Rebouco)
 *
 *   'earliest_start_minutes' => 180
 *     → L'employé ne peut pas commencer avant 3h (ex: Benoît Desaint)
 */
final class StartTimeRule implements RuleInterface
{
  public function getName(): string    { return 'pref-heure-debut'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $prefs        = $context->getMyPrefs();
    $startMinutes = $shift->getStartMinutes();

    // ── Mode : heures fixes uniquement ───────────────────────────────
    if (!empty($prefs['fixed_start_minutes'])) {
      $allowed = array_map('intval', $prefs['fixed_start_minutes']);
      if (!in_array($startMinutes, $allowed, true)) {
        $allowedStr = implode(' ou ', array_map(
          fn(int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60),
          $allowed
        ));
        return [new Violation(
          ruleName:     $this->getName(),
          priority:     $this->getPriority(),
          message:      sprintf(
            '%s doit commencer exclusivement à %s (proposé : %02d:%02d).',
            $shift->employeeName, $allowedStr, $shift->startHour, $shift->startMinute
          ),
          employeeName: $shift->employeeName,
          day:          $shift->day,
        )];
      }
    }

    // ── Mode : heure minimale ─────────────────────────────────────────
    if (isset($prefs['earliest_start_minutes'])) {
      $earliest = (int) $prefs['earliest_start_minutes'];
      if ($startMinutes < $earliest) {
        return [new Violation(
          ruleName:     $this->getName(),
          priority:     $this->getPriority(),
          message:      sprintf(
            '%s ne peut pas commencer avant %02d:%02d (proposé : %02d:%02d).',
            $shift->employeeName,
            intdiv($earliest, 60), $earliest % 60,
            $shift->startHour, $shift->startMinute
          ),
          employeeName: $shift->employeeName,
          day:          $shift->day,
        )];
      }
    }

    return [];
  }
}
