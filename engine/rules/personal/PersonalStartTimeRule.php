<?php

/**
 * Preferences individuelles : contraintes d'heure de debut.
 *
 * Types de contrainte :
 *   'exact_one_of' : doit commencer EXACTEMENT a l'une de ces heures
 *   'min'          : ne peut pas commencer AVANT cette heure
 *
 * === POUR AJOUTER UNE CONTRAINTE ===
 * Ajouter une entree dans CONSTRAINTS.
 */
class PersonalStartTimeRule implements RuleInterface
{
  private const CONSTRAINTS = [
    // Carlos Rebouco : commence exclusivement a 2h00 ou 3h00
    'rebouco' => ['type' => 'exact_one_of', 'times' => ['02:00', '03:00']],
    // Benoit Desaint : ne peut pas commencer avant 3h00
    'desaint' => ['type' => 'min', 'times' => ['03:00']],
  ];

  public function getName(): string        { return 'personal.start_time'; }
  public function getDescription(): string { return 'Contraintes d\'heure de debut individuelles.'; }
  public function getPriority(): int       { return 3; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) {
      return null;
    }

    $name_lower = strtolower($p->employee_name);

    foreach (self::CONSTRAINTS as $key => $constraint) {
      if (!str_contains($name_lower, $key)) {
        continue;
      }

      $start = $p->startMinutes();

      if ($constraint['type'] === 'exact_one_of') {
        $allowed = array_map(
          fn ($t) => (int) explode(':', $t)[0] * 60 + (int) explode(':', $t)[1],
          $constraint['times'],
        );
        if (!in_array($start, $allowed, true)) {
          $allowed_str = implode(' ou ', $constraint['times']);
          return new RuleViolation(
            $this->getName(),
            "{$p->employee_name} doit commencer exactement a {$allowed_str} (propose : {$p->start_time}).",
            'error',
            $p->employee_name,
            $p->date,
          );
        }
      }

      if ($constraint['type'] === 'min') {
        [$mh, $mm] = explode(':', $constraint['times'][0]);
        $min       = (int) $mh * 60 + (int) $mm;
        if ($start < $min) {
          return new RuleViolation(
            $this->getName(),
            "{$p->employee_name} ne peut pas commencer avant {$constraint['times'][0]} (propose : {$p->start_time}).",
            'error',
            $p->employee_name,
            $p->date,
          );
        }
      }
    }

    return null;
  }
}
