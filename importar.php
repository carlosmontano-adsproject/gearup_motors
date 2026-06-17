<?php
// ============================================================
//  importar.php — Importación masiva del inventario desde
//  /img/inv/ (un .zip o una carpeta por moto).
//  - Descomprime los .zip automáticamente.
//  - Excluye PDFs y archivos no-imagen (y basura de macOS: ._* / __MACOSX).
//  - Portada = "Photoroom" (fondo recortado) + fotos originales.
//  - Optimiza cada foto para web. Reanudable (puedes recargar).
//  - Conserva precio/km/datos por slug y NO borra motos sin carpeta.
// ============================================================
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
session_start();
@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/html; charset=utf-8');

$authed = !empty($_SESSION['gearup_auth']) || (isset($_GET['pass']) && hash_equals(ADMIN_PASSWORD, (string)$_GET['pass']));
$passQS = isset($_GET['pass']) ? '&pass=' . urlencode($_GET['pass']) : '';

echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importar inventario — GEAR UP</title>';
echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0d0d10;color:#e8e8ea;max-width:920px;margin:0 auto;padding:24px;line-height:1.55}h1{font-size:22px;margin-bottom:6px}.ok{color:#22c55e}.warn{color:#fbbf24}.err{color:#ef4444}.box{background:#15151a;border:1px solid #2a2a32;border-radius:10px;padding:14px 16px;margin:12px 0}a{color:#6f9bff}a.btn{display:inline-block;background:linear-gradient(135deg,#084DF9,#4F7CFF);color:#fff;text-decoration:none;padding:13px 26px;border-radius:10px;font-weight:700;letter-spacing:.3px}code{background:#1d1d24;padding:2px 6px;border-radius:4px}.row{padding:3px 0;border-bottom:1px solid #1d1d24;font-size:14px}</style></head><body>';
echo '<h1>🏍️ Importar inventario desde /img/inv/</h1>';

if (!$authed) {
  echo '<div class="box">🔒 <b>Acceso restringido.</b><br>Entra primero al panel <a href="admin.html">admin.html</a> e inicia sesión, luego vuelve a abrir esta página.<br>O abre la URL con <code>?pass=TU_CONTRASEÑA</code> al final.</div></body></html>';
  exit;
}

$INV_DIR  = IMG_DIR . '/inv';
$OUT_BASE = IMG_DIR . '/motos';
$MAX_FOTOS = 9;
$BRANDS = ['BMW','DUCATI','APRILIA','KTM','TRIUMPH','KAWASAKI','HONDA','HUSQVARNA','SUZUKI','YAMAHA','CFMOTO','BENELLI','ROYAL ENFIELD','INDIAN','HARLEY','ZONTES','VESPA','ITALIKA','FORD'];

if (!is_dir($INV_DIR)) {
  echo '<div class="box err">No encuentro la carpeta <code>img/inv/</code>.<br>Sube ahí las motos (un <b>.zip</b> o una carpeta por moto) y recarga esta página.</div></body></html>';
  exit;
}

function gu_slug($s){
  $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if($t!==false) $s=$t;
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/','-',$s);
  return trim($s,'-');
}
function gu_title($s){
  if (function_exists('mb_convert_case')) return mb_convert_case(mb_strtolower(trim($s),'UTF-8'), MB_CASE_TITLE, 'UTF-8');
  return ucwords(strtolower(trim($s)));
}
function gu_parse($name, $brands){
  $up = strtoupper($name); $brand='';
  foreach($brands as $b){ if(strpos($up,$b)===0){ $brand=$b; break; } }
  if(!$brand){ foreach($brands as $b){ if(strpos($up,$b)!==false){ $brand=$b; break; } } }
  $year=''; if(preg_match_all('/\b(19|20)\d{2}\b/',$name,$m)){ $year=end($m[0]); }
  $model=trim($name);
  if($brand) $model=trim(preg_replace('/^'.preg_quote($brand,'/').'/i','',$model));
  if($year)  $model=trim(str_replace($year,'',$model));
  $model=trim(preg_replace('/\s+/',' ',$model));
  return [$brand, $model!==''?gu_title($model):$name, $year];
}
function gu_type($model){
  $m=strtoupper($model);
  if(strpos($m,'SCRAMBLER')!==false) return 'Scrambler';
  if(strpos($m,'REBEL')!==false) return 'Cruiser';
  if(preg_match('/ENDURO|KLR|\b701\b/',$m)) return 'Enduro';
  if(preg_match('/\bGS\b|ADV|ADVENTURE|MULTISTRADA|TIGER|\bXR\b/',$m)) return 'Adventure';
  if(preg_match('/PANIGALE|NINJA|\bRS\b|GSXR|GSX-R|SUPERSPORT|\bRR\b/',$m)) return 'Sport';
  return 'Naked';
}
function gu_is_nonmoto($name){
  $up=strtoupper($name);
  return (strpos($up,'REFACC')!==false || strpos($up,'ACCESORIO')!==false || strpos($up,'FORD')!==false || strpos($up,'EXPEDITION')!==false);
}

// ¿Es una imagen real? (descarta ._resource forks de macOS, .DS_Store, etc.)
function gu_is_real_image($f){
  if(!is_file($f)) return false;
  $bn=basename($f);
  if($bn==='' || $bn[0]==='.') return false;
  $e=strtolower(pathinfo($f,PATHINFO_EXTENSION));
  return in_array($e,['jpg','jpeg','png','webp'],true);
}
function gu_has_images($d){ foreach(glob($d.'/*') as $f){ if(gu_is_real_image($f)) return true; } return false; }

// Encuentra (recursivamente) la carpeta que realmente contiene las fotos.
function gu_find_image_dir($d){
  if(gu_has_images($d)) return $d;
  foreach(array_filter(glob($d.'/*'),'is_dir') as $sub){
    if(basename($sub)==='__MACOSX') continue;
    $r=gu_find_image_dir($sub);
    if($r) return $r;
  }
  return null;
}
// Nombre "limpio" para slug/marca/modelo: usa la carpeta de nivel superior,
// salvo que sea un envoltorio "drive-download-..." (entonces baja al nombre real).
function gu_clean_name($topDir,$imgDir){
  $top=basename($topDir);
  if(stripos($top,'drive-download')===false) return $top;
  $d=$imgDir;
  while($d && strlen($d)>=strlen($topDir)){
    $b=basename($d);
    if($b!=='' && stripos($b,'drive-download')===false) return $b;
    if(rtrim($d,'/')===rtrim($topDir,'/')) break;
    $d=dirname($d);
  }
  return $top;
}

// Recolecta y selecciona fotos de una carpeta
function gu_select_images($dir, $max){
  $imgs=[]; foreach(glob($dir.'/*') as $f){ if(gu_is_real_image($f)) $imgs[]=$f; }
  $photoroom=[]; $orig=[];
  foreach($imgs as $f){
    $bn=strtolower(basename($f));
    if(strpos($bn,'photoroom')!==false || strtolower(pathinfo($f,PATHINFO_EXTENSION))==='png') $photoroom[]=$f; else $orig[]=$f;
  }
  sort($photoroom); sort($orig);
  $sel=[];
  if($photoroom) $sel[]=$photoroom[0];           // portada
  foreach($orig as $f){ if(count($sel)>=$max) break; $sel[]=$f; }
  foreach($photoroom as $f){ if(count($sel)>=$max) break; if(!in_array($f,$sel,true)) $sel[]=$f; }
  return [$sel, count($imgs)];
}

// ---------- 1) Descomprime los .zip subidos a img/inv/ ----------
$zipMsgs=[];
$zips=glob($INV_DIR.'/*.{zip,ZIP}', GLOB_BRACE);
if($zips){
  if(!class_exists('ZipArchive')){
    $zipMsgs[]="<div class='row warn'>⚠ Tu servidor no tiene <code>ZipArchive</code>. Extrae los .zip a mano (clic derecho → Extract en el File Manager).</div>";
  } else {
    foreach($zips as $zip){
      $base=preg_replace('/\.zip$/i','',basename($zip));
      $dest=$INV_DIR.'/'.$base;
      if(is_dir($dest)){ continue; } // ya descomprimido en una corrida anterior
      @mkdir($dest,0775,true);
      $za=new ZipArchive();
      if($za->open($zip)===true){ @$za->extractTo($dest); $za->close(); $zipMsgs[]="<div class='row ok'>📦 ".htmlspecialchars(basename($zip))." → descomprimido</div>"; }
      else { $zipMsgs[]="<div class='row warn'>⚠ No pude abrir ".htmlspecialchars(basename($zip))."</div>"; }
    }
  }
}

// ---------- 2) Arma la lista de motos (carpeta + fotos) ----------
$items=[]; // cada uno: ['name'=>nombre, 'dir'=>carpeta con imágenes]
foreach(array_filter(glob($INV_DIR.'/*'),'is_dir') as $c){
  if(basename($c)==='__MACOSX') continue;
  $imgDir=gu_find_image_dir($c);
  if($imgDir) $items[]=['name'=>gu_clean_name($c,$imgDir),'dir'=>$imgDir];
}
usort($items, function($a,$b){ return strcmp($a['name'],$b['name']); });

if (empty($items)) {
  echo '<div class="box">'.implode('',$zipMsgs).'</div>';
  echo '<div class="box warn">No encontré fotos en <code>img/inv/</code>. Sube ahí las motos (un .zip o una carpeta por moto) y recarga.</div></body></html>';
  exit;
}

$go = isset($_GET['go']) && $_GET['go']==='1';
$motos=[]; $mp=[]; $log=[]; $totalImgs=0;

if (!$go) {
  // ---------- VISTA PREVIA ----------
  $nMotos=0; $nNon=0;
  foreach($items as $it){
    $name=$it['name'];
    [$sel,$cnt]=gu_select_images($it['dir'],$MAX_FOTOS); $totalImgs+=$cnt;
    if(gu_is_nonmoto($name)){ $nNon++; $log[]="<div class='row'>🛒 <b>".htmlspecialchars($name)."</b> → marketplace · ".$cnt." fotos (usaré ".count($sel).")</div>"; }
    else { [$b,$mo,$y]=gu_parse($name,$BRANDS); $nMotos++; $log[]="<div class='row'>🏍️ <b>".htmlspecialchars($name)."</b> → ".htmlspecialchars(trim("$b $mo $y"))." · ".$cnt." fotos (usaré ".count($sel).")</div>"; }
  }
  if($zipMsgs) echo '<div class="box">'.implode('',$zipMsgs).'</div>';
  echo '<div class="box">Detecté <b>'.$nMotos.' motos</b> y <b>'.$nNon.' no-motos</b> (irán al marketplace), con <b>'.$totalImgs.' fotos</b> en total.<br>Voy a usar hasta <b>'.$MAX_FOTOS.'</b> por carpeta (portada Photoroom + originales), <b>optimizadas</b> y <b>sin PDFs</b>.</div>';
  echo '<div class="box">'.implode('',$log).'</div>';
  echo '<p><a class="btn" href="?go=1'.$passQS.'">▶ Importar y optimizar ahora</a></p>';
  echo '<p class="warn">⏳ Si hay muchas fotos puede tardar. Si la página se corta a la mitad, <b>vuelve a abrir esta URL con <code>?go=1</code></b>: continúa donde quedó (no repite las ya optimizadas).</p>';
  echo '</body></html>'; exit;
}

// ---------- 3) IMPORTAR (copia + optimiza + arma catálogo) ----------
@mkdir($OUT_BASE,0775,true);
// Carga datos previos para CONSERVAR precio/km/cc/flags (merge por slug)
$prevMotos=[];
if(is_file(DATA_FILE)){ $pj=json_decode(file_get_contents(DATA_FILE),true);
  if(isset($pj['motos'])&&is_array($pj['motos'])) foreach($pj['motos'] as $pm){
    $k=isset($pm['slug'])&&$pm['slug']!==''?$pm['slug']:gu_slug(($pm['brand']??'').' '.($pm['model']??'').' '.($pm['year']??''));
    if($k) $prevMotos[$k]=$pm;
  }
}
$processedSlugs=[];
foreach($items as $idx=>$it){
  $name=$it['name'];
  [$sel,$cnt]=gu_select_images($it['dir'],$MAX_FOTOS);
  if(empty($sel)){ $log[]="<div class='row warn'>⚠ ".htmlspecialchars($name).": sin imágenes, se omite.</div>"; continue; }
  $slug=gu_slug($name); if($slug==='') $slug='item-'.$idx;
  $outDir=$OUT_BASE.'/'.$slug; @mkdir($outDir,0775,true);
  $paths=[];
  foreach($sel as $k=>$src){
    $ext=strtolower(pathinfo($src,PATHINFO_EXTENSION)); if($ext==='jpeg') $ext='jpg';
    $fname=($k===0?'0-portada':$k).'.'.$ext;
    $dst=$outDir.'/'.$fname;
    if(!file_exists($dst)){
      if(@copy($src,$dst)){ @gearup_optimize_image($dst); }
      else { continue; }
    }
    $paths[]='img/motos/'.$slug.'/'.$fname;
  }
  if(empty($paths)) continue;

  if(gu_is_nonmoto($name)){
    $up=strtoupper($name);
    $mp[]=['name'=>gu_title($name),'cat'=>(strpos($up,'FORD')!==false||strpos($up,'EXPEDITION')!==false?'Vehículos':'Refacciones'),'price'=>0,'image'=>$paths[0],'icon'=>'','driveUrl'=>''];
    $log[]="<div class='row ok'>🛒 ".htmlspecialchars($name)." → marketplace (".count($paths)." fotos)</div>";
  } else {
    [$brand,$model,$year]=gu_parse($name,$BRANDS);
    if(isset($prevMotos[$slug])){
      $m=$prevMotos[$slug];                 // conserva precio/km/cc/factura/flags ya capturados
    } else {
      $m=['brand'=>$brand,'model'=>$model,'year'=>$year?(int)$year:0,'type'=>gu_type($model),'cc'=>0,'km'=>0,'owners'=>1,'procedencia'=>'Nacional','factura'=>'Original','financiamiento'=>true,'motoswitch'=>false,'tomaCuenta'=>true,'tdc'=>true,'featured'=>false,'sold'=>false,'soon'=>false,'price'=>0,'mensualidad'=>'','driveUrl'=>''];
    }
    $m['slug']=$slug; $m['images']=$paths; if(!isset($m['video'])) $m['video']='';
    $m['id']=count($motos)+1;
    $motos[]=$m;
    $processedSlugs[$slug]=true;
    $log[]="<div class='row ok'>🏍️ ".htmlspecialchars(trim(($m['brand']??'').' '.($m['model']??'').' '.($m['year']??'')))." (".count($paths)." fotos)".(isset($prevMotos[$slug])?" · datos conservados":"")."</div>";
  }
}

// Conserva las motos que existen en los datos pero NO tienen carpeta en img/inv/
// (p. ej. "Consultar" sin fotos, o "Próximamente"). Mantienen sus datos e imágenes.
foreach($prevMotos as $pslug=>$pm){
  if(isset($processedSlugs[$pslug])) continue;
  $pm['slug']=$pslug;
  if(!isset($pm['images'])||!is_array($pm['images'])) $pm['images']=[];
  if(!isset($pm['video'])) $pm['video']='';
  $pm['id']=count($motos)+1;
  $motos[]=$pm;
  $log[]="<div class='row ok'>📄 ".htmlspecialchars(trim(($pm['brand']??'').' '.($pm['model']??'').' '.($pm['year']??'')))." · sin carpeta, datos conservados</div>";
}

// Conserva el marketplace existente (accesorios genéricos) y agrega los no-motos
$existing=['marketplace'=>[]];
if(is_file(DATA_FILE)){ $j=json_decode(file_get_contents(DATA_FILE),true); if(is_array($j)) $existing=$j; }
$baseMp = isset($existing['marketplace'])&&is_array($existing['marketplace']) ? $existing['marketplace'] : [];
// evita duplicar no-motos en re-ejecuciones: quita los que tengan imagen en img/motos/
$baseMp = array_values(array_filter($baseMp, function($it){ return empty($it['image']) || strpos($it['image'],'img/motos/')===false; }));

$data=['motos'=>$motos,'marketplace'=>array_merge($baseMp,$mp),'updatedAt'=>date('c')];
if(is_file(DATA_FILE)) @copy(DATA_FILE, DATA_FILE.'.bak');
$json=json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

if($zipMsgs) echo '<div class="box">'.implode('',$zipMsgs).'</div>';
if(file_put_contents(DATA_FILE,$json)!==false){
  echo '<div class="box ok">✅ <b>¡Importado!</b> '.count($motos).' motos y '.count($mp).' productos al marketplace.<br>Abre el <a href="admin.html">panel</a> para revisar <b>km, cilindrada y factura</b> de cada moto si hace falta, y luego <b>Publicar</b>.</div>';
} else {
  echo '<div class="box err">❌ No se pudo escribir <code>data/inventario.json</code>. Revisa permisos (carpeta data: 755, archivo: 644).</div>';
}
echo '<div class="box">'.implode('',$log).'</div>';
echo '<p>💡 Cuando ya esté todo bien, puedes <b>borrar el contenido de <code>img/inv/</code></b> (zips y carpetas originales pesadas) para liberar espacio — las versiones optimizadas viven en <code>img/motos/</code>.</p>';
echo '</body></html>';
