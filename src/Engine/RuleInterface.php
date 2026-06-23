<?php

declare(strict_types=1);

namespace Engine;

/**
 * Contrat que doit implémenter chaque règle de planning.
 *
 * Pour ajouter une règle :
 *   1. Créer un fichier dans src/Rules/HR/ ou src/Rules/Preferences/
 *   2. Implémenter cette interface
 *   3. Enregistrer la règle dans src/Config/TeamRulesRegistry.php
 *
 * Aucune autre modification n'est nécessaire.
 */
interface RuleInterface
{
  /** Bloquant : le shift ne peut pas être planifié tel quel */
  public const PRIORITY_HARD = 1;

  /** Avertissement : préférence à respecter si possible */
  public const PRIORITY_SOFT = 2;

  /** Identifiant lisible de la règle (ex: 'max-daily-hours') */
  public function getName(): string;

  /** Priorité : PRIORITY_HARD ou PRIORITY_SOFT */
  public function getPriority(): int;

  /**
   * Évalue si le shift proposé viole cette règle.
   *
   * @param ShiftProposal   $shift       Le shift à évaluer
   * @param WeekContext     $context     Contexte semaine de l'employé
   * @return Violation[]                 Tableau vide = aucune violation
   */
  public function evaluate(ShiftProposal $shift, WeekContext $context): array;
}
