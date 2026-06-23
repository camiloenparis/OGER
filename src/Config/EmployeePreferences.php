<?php

declare(strict_types=1);

namespace Config;

/**
 * Préférences individuelles par employé.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  SOURCE : .github/instructions/preferences-internes-magasins.instructions.md
 *
 *  Quand les instructions changent → modifier UNIQUEMENT ce fichier.
 *  Le moteur et les règles n'ont pas besoin d'être touchés.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Clés disponibles par employé :
 *   rest_days             => string[]    Jours de repos (en anglais ou français)
 *   fixed_start_minutes   => int[]       Heures de début exclusives, en minutes (ex: 120 = 2h00)
 *   earliest_start_minutes => int        Heure de début minimale, en minutes
 *   max_daily_hours       => float       Plafond journalier (ex: Amel : 6.0)
 *   allowed_days          => string[]    Jours autorisés uniquement (ex: week-end only)
 *   can_open              => bool        Autorisation d'ouvrir seul
 *   can_close_alone       => bool        Autorisation de fermer seul
 *   can_close_with_other  => bool        Autorisation de fermer à deux
 */
final class EmployeePreferences
{
  /**
   * Tableau associatif : nom de l'employé (partiel, insensible à la casse) => préférences.
   * Le moteur recherche une correspondance partielle sur le nom complet de l'employé.
   */
  public const ALL = [

    // ── Équipe Boulangerie ──────────────────────────────────────────────

    'Carlos Rebouco' => [
      'rest_days'            => ['monday', 'tuesday'],     // Repos lundi et mardi
      'fixed_start_minutes'  => [120, 180],                // Commence exclusivement à 2h ou 3h
      'can_open'             => true,
      'can_close_alone'      => true,
    ],

    'Benoit Desaint' => [
      'earliest_start_minutes' => 180,                     // Pas avant 3h00
    ],

    // ── Équipe Traiteur ─────────────────────────────────────────────────

    'Amel Zeghad' => [
      'max_daily_hours' => 6.0,                            // Situation de handicap : max 6h/jour
    ],

    // ── Équipe Vente ────────────────────────────────────────────────────

    'Alicia Robert' => [
      'can_open'             => true,
      'can_close_alone'      => true,
    ],

    'Stephanie Aubry-Say' => [
      'can_open'             => true,
      'can_close_alone'      => true,
    ],

    'Erick Autin' => [
      'can_open'             => true,
      'can_close_alone'      => true,
    ],

    'Cassandra Bruhiere' => [
      'can_open'             => true,
      'can_close_with_other' => true,                       // Fermeture à deux uniquement
    ],

    'Lea Serin' => [
      'can_open'             => true,
      'can_close_with_other' => true,
    ],

    'Cloe Boulanger' => [
      'can_open'             => true,
      'can_close_with_other' => true,
    ],

    'Ludivine Gossuin' => [
      'can_open'             => true,
      'can_close_with_other' => true,
    ],

    'Axel Dorges' => [
      'can_open'                => true,
      'can_close_with_other'    => true,
      'earliest_start_minutes'  => 270,                    // Peut commencer à partir de 4h30
    ],

    'Amandine Evain' => [
      'can_open'                => true,
      'can_close_with_other'    => true,
      'earliest_start_minutes'  => 270,                    // 4h30
    ],

    'Oceane Coignard' => [
      'earliest_start_minutes'  => 270,                    // 4h30
    ],

    'Gjylfidone Bajraktaraj' => [
      'earliest_start_minutes'  => 270,                    // 4h30
    ],

    'Elea Lefebvre' => [
      'earliest_start_minutes'  => 270,                    // 4h30
    ],

    'Charlotte Bauduin' => [                               // Bauduin-Bance
      'earliest_start_minutes'  => 270,                    // 4h30
    ],

    'Ines de Jesus' => [
      'allowed_days' => ['saturday', 'sunday'],            // Week-end uniquement
    ],

    'Jade Le Fichous' => [
      'allowed_days' => ['saturday', 'sunday'],            // Week-end uniquement
    ],

    // ── Équipe Vente Leroy Merlin ───────────────────────────────────────

    // Erick Autin, Cassandra Bruhiere, Lea Serin, Cloe Boulanger,
    // Ludivine Gossuin, Axel Dorges, Amandine Evain
    // → peuvent ouvrir ET fermer (déjà défini ci-dessus)

    'Jade Richard' => [
      'can_open'        => true,
      'can_close_alone' => false,
      'can_close_with_other' => false,
    ],
  ];
}
