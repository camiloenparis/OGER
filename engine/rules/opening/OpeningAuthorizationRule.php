<?php

/**
 * Verifie que les shifts couvrant l'ouverture du magasin
 * sont attribues a des personnes autorisees a ouvrir.
 *
 * === POUR MODIFIER LES AUTORISATIONS ===
 * Editer les constantes AUTHORIZED_TO_OPEN ci-dessous.
 * Format : partial_name_lowercase => true
 */
class OpeningAuthorizationRule implements RuleInterface
{
  // Personnes autorisees a ouvrir (seules ou avec partenaire)
  private const AUTHORIZED_TO_OPEN = [
    // Equipe Vente + Vente Leroy Merlin
    'robert'    => true,  // Alicia Robert
    'aubry'     => true,  // Stephane Aubry-Say
    'autin'     => true,  // Erick Autin
    'bruhiere'  => true,  // Cassandra Bruhiere
    'serin'     => true,  // Lea Serin
    'boulanger' => true,  // Cloe Boulanger
    'gossuin'   => true,  // Ludivine Gossuin
    'dorges'    => true,  // Axel Dorges
    'evain'     => true,  // Amandine Evain
    'richard'   => true,  // Jade Richard (LM uniquement)
    // Equipe Boulangerie
    'rebouco'   => true,  // Carlos Rebouco
  ];

  // Heure d'ouverture en minutes depuis minuit par type de jour
  private const OPEN_STANDARD_WD = 360;   // 06:00 Lun-Sam (boulangerie/vente/traiteur/patisserie)
  private const OPEN_STANDARD_SUN = 420;  // 07:00 Dim
  private const OPEN_LM_WD        = 420;  // 07:00 Lun-Sam (Leroy Merlin)
  private const OPEN_LM_SUN       = 480;  // 08:00 Dim (Leroy Merlin)

  public function getName(): string        { return 'auth.opening'; }
  public function getDescription(): string { return 'Verification des autorisations d\'ouverture.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) {
      return null;
    }

    $team_lower = strtolower($p->team);
    $is_lm      = str_contains($team_lower, 'leroy');
    $is_tracked = $is_lm
               || str_contains($team_lower, 'vente')
               || str_contains($team_lower, 'boulangerie')
               || str_contains($team_lower, 'patisserie')
               || str_contains($team_lower, 'traiteur');

    if (!$is_tracked) {
      return null;
    }

    $is_sunday = (new DateTime($p->date))->format('l') === 'Sunday';
    $open_time = $is_lm
      ? ($is_sunday ? self::OPEN_LM_SUN    : self::OPEN_LM_WD)
      : ($is_sunday ? self::OPEN_STANDARD_SUN : self::OPEN_STANDARD_WD);

    if ($p->startMinutes() > $open_time) {
      return null; // ce shift ne couvre pas l'ouverture
    }

    $name_lower = strtolower($p->employee_name);
    foreach (self::AUTHORIZED_TO_OPEN as $key => $_) {
      if (str_contains($name_lower, $key)) {
        return null; // autorise
      }
    }

    return new RuleViolation(
      $this->getName(),
      "{$p->employee_name} n'est pas autorise(e) a ouvrir le magasin le {$p->date} (shift a {$p->start_time}).",
      'warning',
      $p->employee_name,
      $p->date,
    );
  }
}
