<?php

/**
 * Contexte hebdomadaire passe a chaque regle lors de l'evaluation.
 * Permet aux regles de consulter tous les shifts de la semaine
 * (pour valider ex: 11h de repos, 6 jours consecutifs, couverture fermeture).
 */
final class PlanningContext
{
  /** @var ShiftProposal[][] indexes par employee_id */
  private array $by_employee = [];

  /** @var ShiftProposal[][] indexes par date YYYY-MM-DD */
  private array $by_date = [];

  /**
   * @param ShiftProposal[] $all_proposals
   */
  public function __construct(
    public readonly string $week_start,
    public readonly string $location_name,
    public readonly array  $all_proposals,
  ) {
    foreach ($all_proposals as $p) {
      $this->by_employee[$p->employee_id][] = $p;
      $this->by_date[$p->date][]            = $p;
    }
  }

  /** @return ShiftProposal[] */
  public function getForEmployee(string $employee_id): array
  {
    return $this->by_employee[$employee_id] ?? [];
  }

  /** @return ShiftProposal[] */
  public function getForDate(string $date): array
  {
    return $this->by_date[$date] ?? [];
  }

  /** @return string[] 7 dates YYYY-MM-DD du lundi au dimanche */
  public function weekDates(): array
  {
    $dates = [];
    $start = new DateTime($this->week_start);
    for ($i = 0; $i < 7; $i++) {
      $dates[] = (clone $start)->modify("+{$i} days")->format('Y-m-d');
    }
    return $dates;
  }
}
