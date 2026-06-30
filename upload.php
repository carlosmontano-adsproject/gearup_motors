<?php
// ============================================================
//  Subida de archivos (fotos / videos) — GEAR UP Motorcycles
//  Optimiza automáticamente las imágenes al subirlas.
// ============================================================
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['gearup_auth']))        { http_response_code(401); out(['ok'=>false,'error'=>'No autorizado']); }
if (empty($_SERVER['HTTP_X_GEARUP_PANEL'])) { http_response_code(403); out(['ok'=>false,'error'=>'Solicitud no permitida']); }
if (empty($_FILES['file']))                 { http_response_code(400); out(['ok'=>false,'error'=>'No se recibió ningún archivo']); }

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
  $msgs = [1=>'El archivo supera el límite del servidor (php upload_max_filesize)',2=>'El archivo es demasiado grande',3=>'La subida quedó incompleta, intenta de nuevo',6=>'Falta carpeta temporal en el servidor',7=>'No se pudo escribir en disco'];
  http_response_code(400); out(['ok'=>false,'error'=>(isset($msgs[$f['error']]) ? $msgs[$f['error']] : ('Error de subida #'.$f['error']))]);
}

$allowed = explode(',', ALLOWED_EXT);
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) { http_response_code(400); out(['ok'=>false,'error'=>'Tipo de archivo no permitido (.'.$ext.')']); }

$k    = isset($_POST['kind']) ? $_POST['kind'] : 'inv';
$kind = in_array($k, ['mp','inv','site'], true) ? $k : 'inv';
$dir  = IMG_DIR . '/' . $kind;
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$slug = strtolower(isset($_POST['slug']) ? $_POST['slug'] : 'item');
$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
$slug = trim($slug, '-');
if ($slug === '') $slug = 'item';
$slug = substr($slug, 0, 40);

$name = $slug . '-' . time() . '-' . mt_rand(1000, 9999) . '.' . $ext;
$dest = $dir . '/' . $name;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
  http_response_code(500); out(['ok'=>false,'error'=>'No se pudo guardar el archivo. Revisa permisos de la carpeta img/.']);
}

// Optimiza si es imagen (los videos se dejan tal cual)
if (in_array($ext, ['jpg','jpeg','png','webp'], true)) { @gearup_optimize_image($dest); }

out(['ok'=>true, 'path'=>'img/'.$kind.'/'.$name]);
