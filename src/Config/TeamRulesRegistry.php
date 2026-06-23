<?php

declare(strict_types=1);

namespace Config;

use Rules\HR\MaxDailyHoursRule;
use Rules\HR\MaxConsecutiveDaysRule;
use Rules\HR\MinRestBetweenShiftsRule;
use Rules\HR\ApprenticeStartTimeRule;
use Rules\Preferences\RestDayRule;
use Rules\Preferences\MaxDailyHoursCapRule;
use Rules\Preferences\StartTimeRule;
use Rules\Preferences\WeekdayAvailabilityRule;

/**
 * Registre des règles actives par équipe.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  Pour ajouter une nouvelle règle :
 *    1. Créer le fichier dans src/Rules/HR/ ou src/Rules/Preferences/
 *    2. L'ajouter ici dans les tableaux appropriés
 *  Aucune autre modification n'est nécessaire.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class TeamRulesRegistry
{
  /**
   * Renvoie les instances de règles à appliquer pour une équipe donnée.
   *
   * @param string $team  Ex: 'boulangerie', 'vente', 'leroy-merlin'
   * @return \Engine\RuleInterface[]
   */
  public static function getRulesForTeam(string $team): array
  {
    // Règles communes à toutes les équipes
    $common = [
      new MaxDailyHoursRule(),
      new MaxConsecutiveDaysRule(),
      new MinRestBetweenShiftsRule(),
      new ApprenticeStartTimeRule(),
      // Préférences individuelles (s'appliquent à tous : la règle vérifie si l'employé est concerné)
      new RestDayRule(),
      new MaxDailyHoursCapRule(),
      new StartTimeRule(),
      new WeekdayAvailabilityRule(),
    ];

    // Règles spécifiques par équipe (à compléter si besoin)
    $teamSpecific = match (strtolower(trim($team))) {
      'boulangerie'  => [],
      'patisserie'   => [],
      'traiteur'     => [],
      'vente'        => [],
      'leroy-merlin', 'vente leroy merlin', 'vente leroy-merlin' => [],
      default        => [],
    };

    return array_merge($common, $teamSpecific);
  }

  /**
   * Mapping nom d'équipe Combo → clé CSV dans data/planning/besoins/
   */
  public static function getCsvFileName(string $team): string
  {
    return match (strtolower(trim($team))) {
      'boulangerie'                                          => 'boulangerie.csv',
      'patisserie', 'pâtisserie'                             => 'patisserie.csv',
      'traiteur'                                             => 'traiteur.csv',
      'vente'                                                => 'vente.csv',
      'leroy-merlin', 'vente leroy merlin', 'vente leroy-merlin',
      'vente leroy merlin'                                   => 'leroy-merlin.csv',
      default                                                => '',
    };
  }
}
