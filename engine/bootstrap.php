<?php

/**
 * Bootstrap du moteur de planning.
 *
 * === POUR AJOUTER UNE REGLE ===
 * 1. Creer engine/rules/{categorie}/MaRegle.php implementant RuleInterface
 * 2. Ajouter un require_once ici
 * 3. Appeler $registry->register(new MaRegle()) dans create_rule_registry()
 */

// Core
require_once __DIR__ . '/rules/RuleInterface.php';
require_once __DIR__ . '/rules/RuleViolation.php';
require_once __DIR__ . '/ShiftProposal.php';
require_once __DIR__ . '/PlanningContext.php';
require_once __DIR__ . '/RuleRegistry.php';
require_once __DIR__ . '/PlanningEngine.php';
require_once __DIR__ . '/ProposalGenerator.php';

// Regles RH (priorite 2)
require_once __DIR__ . '/rules/hr/MaxDailyHoursRule.php';
require_once __DIR__ . '/rules/hr/MaxConsecutiveDaysRule.php';
require_once __DIR__ . '/rules/hr/MinRestBetweenShiftsRule.php';
require_once __DIR__ . '/rules/hr/BreakRequirementRule.php';
require_once __DIR__ . '/rules/hr/ApprenticeStartTimeRule.php';

// Regles d'autorisation (priorite 2)
require_once __DIR__ . '/rules/opening/OpeningAuthorizationRule.php';
require_once __DIR__ . '/rules/opening/ClosingAuthorizationRule.php';

// Preferences individuelles (priorite 3)
require_once __DIR__ . '/rules/personal/PersonalRestDayRule.php';
require_once __DIR__ . '/rules/personal/PersonalStartTimeRule.php';
require_once __DIR__ . '/rules/personal/PersonalMaxHoursRule.php';
require_once __DIR__ . '/rules/personal/WeekdayRestrictionRule.php';

/**
 * Cree et retourne un RuleRegistry pre-configure avec toutes les regles actives.
 * Pour desactiver une regle : commenter la ligne register() correspondante.
 */
function create_rule_registry(): RuleRegistry
{
  $registry = new RuleRegistry();

  // Regles RH
  $registry->register(new MaxDailyHoursRule());
  $registry->register(new MaxConsecutiveDaysRule());
  $registry->register(new MinRestBetweenShiftsRule());
  $registry->register(new BreakRequirementRule());
  $registry->register(new ApprenticeStartTimeRule());

  // Autorisations ouverture/fermeture
  $registry->register(new OpeningAuthorizationRule());
  $registry->register(new ClosingAuthorizationRule());

  // Preferences individuelles
  $registry->register(new PersonalRestDayRule());
  $registry->register(new PersonalStartTimeRule());
  $registry->register(new PersonalMaxHoursRule());
  $registry->register(new WeekdayRestrictionRule());

  return $registry;
}
