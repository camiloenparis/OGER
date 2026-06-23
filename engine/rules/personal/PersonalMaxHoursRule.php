<?php

/**
 * Preferences individuelles : limite d'heures journalieres (ex: situation de handicap).
 *
 * === POUR AJOUTER UNE LIMITE ===
 * Ajouter une entree dans PERSONAL_MAX_HOURS.
 */
class PersonalMaxHoursRule implements RuleInterface
{
  // Format : partial_name_lower => max_hours
  private const PERSONAL_MAX_HOURS = [
    'zeghad' => 6.0,  // Amel Zeghad : situation de handicap, max 6h
  ];

  public function getName(): string        { return 'personal.max_daily_hours'; }
  public function getDescription(): string { return 'Limite d\'heures journalieres individuelle (ex: handicap).'; }
  public function getPriority(): int       { return 3; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) {
      return null;
    }

    $name_lower = strtolower($p->employee_name);

    foreach (self::PERSONAL_MAX_HOURS as $key => $max_hours) {
      if (!str_contains($name_lower, $key)) {
        continue;
      }
      if ($p->durationHours() > $max_hours) {
        return new RuleViolation(
          $this->getName(),
          "{$p->employee_name} ne peut pas travailler plus de {$max_hours}h par jour (propose : {$p->durationHours()}h).",
          'error',
          $p->employee_name,
          $p->date,
        );
      }
    }

    return null;
  }
}
