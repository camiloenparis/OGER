<?php

/**
 * Preferences individuelles : jours de repos habituels.
 *
 * === POUR AJOUTER UNE PREFERENCE ===
 * Ajouter une entree dans PERSONAL_REST_DAYS :
 *   'partial_lastname_lower' => ['Monday', 'Tuesday', ...]
 */
class PersonalRestDayRule implements RuleInterface
{
  private const PERSONAL_REST_DAYS = [
    'rebouco' => ['Monday', 'Tuesday'],  // Carlos Rebouco : repos lundi et mardi
  ];

  public function getName(): string        { return 'personal.rest_day'; }
  public function getDescription(): string { return 'Jours de repos personnels habituels.'; }
  public function getPriority(): int       { return 3; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day) {
      return null; // deja un jour de repos, pas de violation
    }

    $name_lower = strtolower($p->employee_name);
    $day_name   = (new DateTime($p->date))->format('l');

    foreach (self::PERSONAL_REST_DAYS as $key => $rest_days) {
      if (!str_contains($name_lower, $key)) {
        continue;
      }
      if (in_array($day_name, $rest_days, true)) {
        return new RuleViolation(
          $this->getName(),
          "{$p->employee_name} est programme(e) le {$day_name} ({$p->date}) alors que c'est son jour de repos habituel.",
          'warning',
          $p->employee_name,
          $p->date,
        );
      }
    }

    return null;
  }
}
