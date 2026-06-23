<?php

declare(strict_types=1);

namespace Engine;

/**
 * Charge et expose les besoins horaires en personnel depuis un fichier CSV.
 *
 * Format CSV attendu (séparateur ;) :
 *   ;LUNDI;MARDI;MERCREDI;JEUDI;VENDREDI;SAMEDI;DIMANCHE
 *   2H;1;1;1;1;1;1;1
 *   3H;2;2;2;2;2;2;2
 *   …
 */
final class StaffingNeeds
{
  // $needs[dayIndex 0=Lundi][hour 0-23] = min_employees
  private array $needs = [];

  public static function fromCsv(string $csvPath): self
  {
    $instance = new self();

    if (!file_exists($csvPath)) {
      return $instance;
    }

    $lines = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) {
      return $instance;
    }

    array_shift($lines); // enlever la ligne d'entête

    foreach ($lines as $line) {
      $cols = str_getcsv($line, ';');
      if (count($cols) < 2) continue;

      $hourLabel = trim($cols[0]);
      // Extraire le nombre depuis "2H", "2h00", "14h", "00h00", etc.
      preg_match('/(\d+)/', $hourLabel, $m);
      if (empty($m[1])) continue;
      $hour = (int) $m[1];

      for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
        $raw = isset($cols[$dayIndex + 1]) ? trim($cols[$dayIndex + 1]) : '0';
        $instance->needs[$dayIndex][$hour] = (int) $raw;
      }
    }

    return $instance;
  }

  /** Nombre min d'employés requis à une heure et un jour donnés */
  public function getNeeded(int $dayIndex, int $hour): int
  {
    return $this->needs[$dayIndex][$hour] ?? 0;
  }

  /**
   * Profil horaire complet d'un jour.
   * @return int[]  Indexed by hour (0-23)
   */
  public function getDayProfile(int $dayIndex): array
  {
    $base = array_fill(0, 24, 0);
    foreach ($this->needs[$dayIndex] ?? [] as $hour => $count) {
      $base[$hour] = $count;
    }
    return $base;
  }

  /** Première heure de la journée avec un besoin > 0 */
  public function getFirstActiveHour(int $dayIndex): ?int
  {
    foreach ($this->getDayProfile($dayIndex) as $hour => $count) {
      if ($count > 0) return $hour;
    }
    return null;
  }

  /** Dernière heure de la journée avec un besoin > 0 */
  public function getLastActiveHour(int $dayIndex): ?int
  {
    $last = null;
    foreach ($this->getDayProfile($dayIndex) as $hour => $count) {
      if ($count > 0) $last = $hour;
    }
    return $last;
  }
}
