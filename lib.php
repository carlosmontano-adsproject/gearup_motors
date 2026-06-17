<?php
// ============================================================
//  lib.php — utilidades compartidas (optimización de imágenes)
// ============================================================
if (!defined('GEARUP_MAXDIM')) define('GEARUP_MAXDIM', 1600); // lado más largo, en px
if (!defined('GEARUP_JPGQ'))   define('GEARUP_JPGQ', 82);     // calidad JPG

/**
 * Optimiza una imagen EN SU LUGAR: la redimensiona (máx GEARUP_MAXDIM) y la recomprime.
 * Conserva transparencia en PNG. Devuelve true si la modificó, false si no pudo / no hizo falta.
 */
function gearup_optimize_image($path) {
  if (!function_exists('imagecreatetruecolor')) return false; // GD no disponible
  $info = @getimagesize($path);
  if (!$info) return false;
  $w = (int)$info[0]; $h = (int)$info[1]; $mime = $info['mime'];
  if ($w < 1 || $h < 1) return false;

  // Si ya es chica y ligera, no la toques
  if (max($w, $h) <= GEARUP_MAXDIM && @filesize($path) < 500 * 1024) return false;

  if ($mime === 'image/jpeg')      $im = @imagecreatefromjpeg($path);
  elseif ($mime === 'image/png')   $im = @imagecreatefrompng($path);
  elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($path);
  else return false;
  if (!$im) return false;

  $scale = min(1, GEARUP_MAXDIM / max($w, $h));
  $nw = max(1, (int)round($w * $scale));
  $nh = max(1, (int)round($h * $scale));

  $out = imagecreatetruecolor($nw, $nh);
  if ($mime === 'image/png') {
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $t = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $nw, $nh, $t);
  }
  imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);

  if ($mime === 'image/png') @imagepng($out, $path, 6);
  else                       @imagejpeg($out, $path, GEARUP_JPGQ);

  imagedestroy($im);
  imagedestroy($out);
  return true;
}
