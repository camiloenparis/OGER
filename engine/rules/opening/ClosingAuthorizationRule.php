<?php

/**
 * Verifie les autorisations de fermeture du magasin.
 *
 * Logique Vente :
 *   - Peut fermer seul : Alicia Robert, Stephane Aubry-Say, Erick Autin
 *   - Peut fermer mais besoin d'un(e) partenaire autorise(e) :
 *     Cassandra Bruhiere, Lea Serin, Cloe Boulanger, Ludivine Gossuin, Axel Dorges, Amandine Evain
 *
 * Logique Vente Leroy Merlin :
 *   - Peut fermer : Erick Autin, Cassandra Bruhiere, Lea Serin,
 *     Cloe Boulanger, Ludivine Gossuin, Axel Dorges, Amandine Evain
 *   - NE peut PAS fermer : Jade Richard
 *
 * === POUR MODIFIER LES AUTORISATIONS ===
 * Editer les constantes de cette classe.
 */
class ClosingAuthorizationRule implements RuleInterface
{
  private const CLOSE_ALONE_VENTE   = ['robert', 'aubry', 'autin', 'rebouco'];
  private const CLOSE_PARTNER_VENTE = ['bruhiere', 'serin', 'boulanger', 'gossuin', 'dorges', 'evain'];
  private const CLOSE_LM            = ['autin', 'bruhiere', 'serin', 'boulanger', 'gossuin', 'dorges', 'evain'];
  private const CANNOT_CLOSE_LM     = ['richard'];

  private const CLOSE_STANDARD = 1200; // 20:00
  private const CLOSE_LM_WD    = 1200; // 20:00
  private const CLOSE_LM_SUN   = 1080; // 18:00

  public function getName(): string        { return 'auth.closing'; }
  public function getDescription(): string { return 'Verification des autorisations de fermeture.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->end_time === null) {
      return null;
    }

    $team_lower = strtolower($p->team);
    $is_lm      = str_contains($team_lower, 'leroy');
    $is_vente   = str_contains($team_lower, 'vente') && !$is_lm;

    if (!$is_vente && !$is_lm) {
      return null;
    }

    $is_sunday  = (new DateTime($p->date))->format('l') === 'Sunday';
    $close_time = $is_lm
      ? ($is_sunday ? self::CLOSE_LM_SUN : self::CLOSE_LM_WD)
      : self::CLOSE_STANDARD;

    if ($p->endMinutes() < $close_time) {
      return null; // ce shift ne couvre pas la fermeture
    }

    $name_lower = strtolower($p->employee_name);

    // --- Leroy Merlin ---
    if ($is_lm) {
      foreach (self::CANNOT_CLOSE_LM as $key) {
        if (str_contains($name_lower, $key)) {
          return new RuleViolation(
            $this->getName(),
            "{$p->employee_name} n'est pas autorise(e) a fermer Leroy Merlin le {$p->date}.",
            'error',
            $p->employee_name,
            $p->date,
          );
        }
      }
      foreach (self::CLOSE_LM as $key) {
        if (str_contains($name_lower, $key)) {
          return null;
        }
      }
      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} n'est pas autorise(e) a fermer Leroy Merlin le {$p->date}.",
        'warning',
        $p->employee_name,
        $p->date,
      );
    }

    // --- Vente : peut fermer seul ---
    foreach (self::CLOSE_ALONE_VENTE as $key) {
      if (str_contains($name_lower, $key)) {
        return null;
      }
    }

    // --- Vente : peut fermer avec partenaire ---
    $needs_partner = false;
    foreach (self::CLOSE_PARTNER_VENTE as $key) {
      if (str_contains($name_lower, $key)) {
        $needs_partner = true;
        break;
      }
    }

    if ($needs_partner) {
      // Chercher un autre fermeture autorisee ce jour dans la meme equipe
      $closers = array_filter(
        $ctx->getForDate($p->date),
        function (ShiftProposal $other) use ($p, $close_time) {
          if ($other->employee_id === $p->employee_id) return false;
          if ($other->is_rest_day || $other->end_time === null) return false;
          if ($other->endMinutes() < $close_time) return false;
          $ot = strtolower($other->team);
          return str_contains($ot, 'vente') && !str_contains($ot, 'leroy');
        }
      );

      $all_keys = array_merge(self::CLOSE_ALONE_VENTE, self::CLOSE_PARTNER_VENTE);
      foreach ($closers as $closer) {
        $cn = strtolower($closer->employee_name);
        foreach ($all_keys as $key) {
          if (str_contains($cn, $key)) {
            return null; // partenaire valide trouve
          }
        }
      }

      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} a besoin d'un(e) partenaire autorise(e) pour fermer le {$p->date}.",
        'warning',
        $p->employee_name,
        $p->date,
      );
    }

    // Ni seul ni partenaire → non autorise
    return new RuleViolation(
      $this->getName(),
      "{$p->employee_name} n'est pas autorise(e) a fermer le magasin le {$p->date}.",
      'warning',
      $p->employee_name,
      $p->date,
    );
  }
}
