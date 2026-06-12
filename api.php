<?php
// ============================================================
//  API del panel — GEAR UP Motorcycles
//  Acciones: login, check, logout, save
// ============================================================
require __DIR__ . '/config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function require_auth(){ if (empty($_SESSION['gearup_auth'])) { http_response_code(401); out(['ok'=>false,'error'=>'Sesión expirada. Vuelve a iniciar sesión.']); } }
function require_panel(){ if (empty($_SERVER['HTTP_X_GEARUP_PANEL'])) { http_response_code(403); out(['ok'=>false,'error'=>'Solicitud no permitida.']); } }

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'login') {
  $body = json_decode(file_get_contents('php://input'), true);
  $pass = isset($body['password']) ? (string)$body['password'] : '';
  if (hash_equals(ADMIN_PASSWORD, $pass)) { $_SESSION['gearup_auth'] = true; out(['ok'=>true]); }
  http_response_code(401); out(['ok'=>false,'error'=>'Contraseña incorrecta']);
}

if ($action === 'check')  { out(['auth' => !empty($_SESSION['gearup_auth'])]); }
if ($action === 'logout') { $_SESSION = []; session_destroy(); out(['ok'=>true]); }

if ($action === 'save') {
  require_auth(); require_panel();
  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data) || !isset($data['motos']) || !is_array($data['motos'])) {
    http_response_code(400); out(['ok'=>false,'error'=>'Datos inválidos']);
  }
  if (!isset($data['marketplace']) || !is_array($data['marketplace'])) $data['marketplace'] = [];
  $data['updatedAt'] = date('c');

  $dir = dirname(DATA_FILE);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if (is_file(DATA_FILE)) @copy(DATA_FILE, DATA_FILE . '.bak'); // respaldo simple
  if (file_put_contents(DATA_FILE, $pretty) === false) {
    http_response_code(500); out(['ok'=>false,'error'=>'No se pudo escribir data/inventario.json. Revisa permisos (carpeta data: 755, archivo: 644/664).']);
  }
  out(['ok'=>true]);
}

http_response_code(404);
out(['ok'=>false,'error'=>'Acción desconocida']);
