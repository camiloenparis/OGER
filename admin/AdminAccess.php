<?php

final class AdminAccess
{
  public static function secret(string $name): string
  {
    foreach (['/.local/secrets/','/secrets/'] as $directory) {
      $path = dirname(__DIR__) . $directory . $name . '.txt';
      if (is_readable($path)) return trim(file_get_contents($path));
    }
    return '';
  }

  public static function startSession(): void
  {
    $dir = dirname(__DIR__) . '/.local/admin/sessions';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Sessions admin indisponibles.');
    session_save_path($dir);
    session_name('oger_admin');
    session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict',
      'cookie_secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'use_strict_mode'=>true]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
  }

  public static function authenticated(): bool
  {
    $password = self::secret('admin_password');
    return $password !== '' && ($_SESSION['admin_until'] ?? 0) > time()
      && hash_equals(hash('sha256', $password), $_SESSION['password_version'] ?? '');
  }

  public static function login(string $password): void
  {
    if (($_SESSION['retry_after'] ?? 0) > time()) throw new RuntimeException('Attendez une minute avant de reessayer.', 429);
    $expected = self::secret('admin_password');
    if ($expected === '') throw new RuntimeException('Ajoutez un mot de passe dans .local/secrets/admin_password.txt.', 503);
    $correct = str_starts_with($expected, '$2') || str_starts_with($expected, '$argon')
      ? password_verify($password, $expected) : hash_equals($expected, $password);
    if (!$correct) {
      $_SESSION['attempts'] = ($_SESSION['attempts'] ?? 0) + 1;
      if ($_SESSION['attempts'] >= 5) { $_SESSION['retry_after'] = time() + 60; $_SESSION['attempts'] = 0; }
      throw new RuntimeException('Mot de passe incorrect.', 401);
    }
    session_regenerate_id(true);
    $_SESSION['attempts'] = 0;
    $_SESSION['admin_until'] = time() + 3600;
    $_SESSION['password_version'] = hash('sha256', $expected);
  }
}
