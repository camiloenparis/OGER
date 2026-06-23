<?php

declare(strict_types=1);

namespace Rules\Preferences;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/**
 * Règle Préférence (HARD) : certains employés ne travaillent que le week-end.
 * Ex : Ines de Jesus et Jade Le Fichous (uniquement samedi et dimanche).
 *
 * Config dans : src/Config/EmployeePreferences.php  →  clé 'weekdays_only' : false
 *               ou  'allowed_days' => ['saturday', 'sunday']
 */
final class WeekdayAvailabilityRule implements RuleInterface
{
  public function getName(): string    { return 'pref-disponibilite-jours'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $prefs = $context->getMyPrefs();

    if (empty($prefs['allowed_days'])) {
      return [];
    }

    try {
      $dayName = strtolower((new \DateTime($shift->day))->format('l'));
    } catch (\Exception $e) {
      return [];
    }

    $allowed = array_map('strtolower', $prefs['allowed_days']);

    if (!in_array($dayName, $allowed, true)) {
      $allowedFr = implode(', ', array_map(fn(string $d) => match ($d) {
        'monday'    => 'lundi',
        'tuesday'   => 'mardi',
        'wednesday' => 'mercredi',
        'thursday'  => 'jeudi',
        'friday'    => 'vendredi',
        'saturday'  => 'samedi',
        'sunday'    => 'dimanche',
        default     => $d,
      }, $allowed));

      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s ne peut travailler que : %s (jour proposé : %s).',
          $shift->employeeName, $allowedFr, $dayName
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    return [];
  }
}
