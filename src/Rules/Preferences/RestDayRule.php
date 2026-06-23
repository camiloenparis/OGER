<?php

declare(strict_types=1);

namespace Rules\Preferences;

use Engine\RuleInterface;
use Engine\ShiftProposal;
use Engine\Violation;
use Engine\WeekContext;

/**
 * Règle Préférence (HARD) : jours de repos imposés pour certains employés.
 * Ex : Carlos Rebouco ne travaille pas le lundi ni le mardi.
 *
 * Config dans : src/Config/EmployeePreferences.php  →  clé 'rest_days'
 */
final class RestDayRule implements RuleInterface
{
  private static array $dayTranslations = [
    'monday'    => 'lundi',
    'tuesday'   => 'mardi',
    'wednesday' => 'mercredi',
    'thursday'  => 'jeudi',
    'friday'    => 'vendredi',
    'saturday'  => 'samedi',
    'sunday'    => 'dimanche',
  ];

  public function getName(): string    { return 'pref-jour-repos'; }
  public function getPriority(): int   { return self::PRIORITY_HARD; }

  public function evaluate(ShiftProposal $shift, WeekContext $context): array
  {
    $prefs    = $context->getMyPrefs();
    $restDays = $prefs['rest_days'] ?? [];

    if (empty($restDays)) {
      return [];
    }

    try {
      $dayName = strtolower((new \DateTime($shift->day))->format('l')); // 'monday', etc.
    } catch (\Exception $e) {
      return [];
    }

    $restDaysNorm = array_map('strtolower', $restDays);

    // Accepter aussi bien 'lundi' que 'monday'
    $dayFr = self::$dayTranslations[$dayName] ?? $dayName;

    if (in_array($dayName, $restDaysNorm, true) || in_array($dayFr, $restDaysNorm, true)) {
      return [new Violation(
        ruleName:     $this->getName(),
        priority:     $this->getPriority(),
        message:      sprintf(
          '%s ne travaille pas le %s (jour de repos).',
          $shift->employeeName, $dayFr
        ),
        employeeName: $shift->employeeName,
        day:          $shift->day,
      )];
    }

    return [];
  }
}
