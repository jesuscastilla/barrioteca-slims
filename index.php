<?php
/**
 * 🌿 Barrioteca Acalencá — Página de bienvenida + Login
 * 
 * Sin parámetros: landing page con botón "Acceso Staff"
 * Con ?p=login: redirige al OPAC original para iniciar sesión
 * 
 * El OPAC original se ha respaldado en index_opac_original.php
 */

// Si se solicita login o cualquier funcionalidad del OPAC,
// cargar el OPAC original que maneja el sistema de autenticación
if (!empty($_GET)) {
    require __DIR__ . '/index_opac_original.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Barrioteca Acalencá — Staff</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
      background: #f5f5f0;
      color: #141414;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .card {
      background: white;
      border-radius: 28px;
      padding: 48px 40px;
      max-width: 420px;
      width: 100%;
      text-align: center;
      box-shadow: 0 4px 24px rgba(0,0,0,0.06);
      border: 1px solid #e5e5e0;
    }
    .logo {
      font-size: 2.5rem;
      margin-bottom: 8px;
    }
    h1 {
      font-family: 'Playfair Display', Georgia, serif;
      font-style: italic;
      font-size: 1.6rem;
      font-weight: 700;
      margin-bottom: 4px;
      color: #141414;
    }
    .subtitle {
      font-size: 0.8rem;
      color: #888;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 32px;
    }
    .btn {
      display: inline-block;
      background: #141414;
      color: #f5f5f0;
      padding: 14px 36px;
      border-radius: 14px;
      font-weight: 700;
      font-size: 0.95rem;
      text-decoration: none;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      transition: all 0.2s;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    }
    .btn:hover {
      background: #2a2a2a;
      box-shadow: 0 4px 16px rgba(0,0,0,0.18);
      transform: translateY(-1px);
    }
    .btn:active {
      transform: scale(0.97);
    }
    .footer {
      margin-top: 28px;
      font-size: 0.7rem;
      color: #aaa;
      line-height: 1.5;
    }
    .divider {
      width: 40px;
      height: 3px;
      background: #d4a853;
      border-radius: 2px;
      margin: 16px auto 24px;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">📚</div>
    <h1>Barrioteca Acalencá</h1>
    <p class="subtitle">Sistema de Gestión Bibliotecaria</p>
    <div class="divider"></div>
    <a href="admin/" class="btn">🔐 Acceso Staff</a>
    <p class="footer">
      Acceso de administradoras para el staff.<br>
      Si no tienes credenciales, contacta con la coordinación.
    </p>
  </div>
</body>
</html>