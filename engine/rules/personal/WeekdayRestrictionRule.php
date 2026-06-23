<?php

/**
 * Preferences individuelles : restriction aux jours travailles autorises.
 *
 * === POUR AJOUTER UNE RESTRICTION ===
 * Ajouter une entree dans RESTRICTIONS.
 * Format : partial_name_lower => ['only' => ['Saturday', 'Sunday', ...]]
 */
class WeekdayRestrictionRule implements RuleInterface
{
  private const RESTRICTIONS = [
    'de jesus' => ['only' => ['Saturday', 'Sunday']],  // Ines de Jesus : sam/dim seulement
    'fichous'  => ['only' => ['Saturday', 'Sunday']],  // Jade le Fichous : sam/dim seulement
  ];

  public function getName(): string        { return 'personal.weekday_restriction'; }
  public function getDescription(): string { return 'Restriction aux jours travailles autorises.'; }
  public function getPriority(): int       { return 3; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day) {
      return null;
    }

    $full_name_lower = strtolower($p->employee_name);
    $day_name        = (new DateTime($p->date))->format('l');

    foreach (self::RESTRICTIONS as $key => $restriction) {
      if (!str_contains($full_name_lower, $key)) {
        continue;
      }
      if (isset($restriction['only']) && !in_array($day_name, $restriction['only'], true)) {
        $allowed_str = implode(', ', $restriction['only']);
        return new RuleViolation(
          $this->getName(),
          "{$p->employee_name} ne peut travailler que le {$allowed_str} (propose : {$day_name} {$p->date}).",
          'error',
          $p->employee_name,
          $p->date,
        );
      }
    }

    return null;
  }
}
