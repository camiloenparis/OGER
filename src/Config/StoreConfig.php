<?php

declare(strict_types=1);

namespace Config;

/**
 * Configuration des horaires d'ouverture/fermeture par magasin.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  SOURCE : .github/instructions/regles-planning.instructions.md
 *
 *  Quand les horaires changent → modifier UNIQUEMENT ce fichier.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * open_minutes / close_minutes : exprimés en minutes depuis minuit.
 * Clé 'sunday' surcharge les valeurs de semaine pour le dimanche.
 */
final class StoreConfig
{
  /**
   * Renvoie la config pour une équipe donnée.
   * Équipes reconnues : boulangerie, patisserie, traiteur, vente, leroy-merlin
   */
  public static function forTeam(string $team): array
  {
    $team = strtolower(trim($team));

    if (str_contains($team, 'leroy') || str_contains($team, 'merlin')) {
      return self::LEROY_MERLIN;
    }

    return self::DEFAULT_STORE;
  }

  /**
   * Boulangerie, Pâtisserie, Traiteur, Vente
   * Lun-Sam : ouverture 6h, fermeture 20h
   * Dimanche : ouverture 7h, fermeture 20h
   */
  private const DEFAULT_STORE = [
    'open_minutes'         => 6 * 60,   // 360 min
    'close_minutes'        => 20 * 60,  // 1200 min
    'sunday_open_minutes'  => 7 * 60,   // 420 min
    'sunday_close_minutes' => 20 * 60,  // 1200 min
  ];

  /**
   * Leroy Merlin
   * Lun-Sam : ouverture 7h, fermeture 20h
   * Dimanche : ouverture 8h, fermeture 18h
   */
  private const LEROY_MERLIN = [
    'open_minutes'         => 7 * 60,   // 420 min
    'close_minutes'        => 20 * 60,  // 1200 min
    'sunday_open_minutes'  => 8 * 60,   // 480 min
    'sunday_close_minutes' => 18 * 60,  // 1080 min
  ];
}
