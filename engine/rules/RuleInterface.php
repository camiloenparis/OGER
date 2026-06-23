<?php

/**
 * Contrat que toute regle de planning doit implementer.
 *
 * === POUR AJOUTER UNE REGLE ===
 * 1. Creer engine/rules/{categorie}/MaRegle.php implementant RuleInterface
 * 2. Ajouter un require_once dans engine/bootstrap.php
 * 3. Appeler $registry->register(new MaRegle()) dans create_rule_registry()
 */
interface RuleInterface
{
  public function getName(): string;
  public function getDescription(): string;

  /**
   * 1 = besoins en effectif | 2 = RH obligatoires | 3 = preferences individuelles
   */
  public function getPriority(): int;

  public function evaluate(ShiftProposal $proposal, PlanningContext $context): ?RuleViolation;
}
