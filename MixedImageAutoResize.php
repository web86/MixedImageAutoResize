<?php
/**
 * MixedImageAutoResize
 * MODX 2.8.8
 * Событие: OnDocFormSave
 */

if ($modx->event->name !== 'OnDocFormSave') {
    return;
}

/** @var modResource $resource */
if (empty($resource) || !($resource instanceof modResource)) {
    return;
}

// ====== ЗАЩИТА ОТ РЕКУРСИИ ======
if ($modx->getOption('mi_autoresize_processed')) {
    return;
}
$modx->setOption('mi_autoresize_processed', true);

// ====== НАСТРОЙКИ ======
$tvName  = 'image'; // имя TV MixedImage
$quality = 82;

$sizes = [
    '-L' => [645, 373],
    '-S' => [200, 116],
];
// =======================

// ====== ПОЛУЧАЕМ ДАННЫЕ ======
$image = $resource->getTVValue($tvName);
if (empty($image)) {
    return;
}

$basePath = $modx->getOption('base_path');
$sourceFile = $basePath . ltrim($image, '/');

if (!file_exists($sourceFile)) {
    return;
}

$pathInfo = pathinfo($sourceFile);
$ext  = strtolower($pathInfo['extension'] ?? '');
$dir  = $pathInfo['dirname'];
$name = $pathInfo['filename'];

if (!in_array($ext, ['jpg', 'jpeg'])) {
    return;
}

$alias = $resource->get('alias');
if (empty($alias)) {
    return;
}

// ====== ПРАВИЛЬНОЕ ИМЯ ======
$correctName = $alias;
$correctFile = $dir . '/' . $correctName . '.' . $ext;
$newRelative = str_replace($basePath, '', $correctFile);

// ====== RENAME ЕСЛИ НУЖНО ======
if ($sourceFile !== $correctFile) {

    // если файл с правильным именем уже есть — удаляем
    if (file_exists($correctFile)) {
        unlink($correctFile);
    }

    if (!rename($sourceFile, $correctFile)) {
        return;
    }

    // ====== ОБНОВЛЯЕМ TV ======
    /** @var modTemplateVar $tv */
    $tv = $modx->getObject('modTemplateVar', ['name' => $tvName]);
    if ($tv) {
        $tvr = $modx->getObject('modTemplateVarResource', [
            'tmplvarid' => $tv->get('id'),
            'contentid' => $resource->get('id'),
        ]);

        if ($tvr) {
            $tvr->set('value', ltrim($newRelative, '/'));
            $tvr->save();
        }
    }

    $sourceFile = $correctFile;
}

// ====== ЗАГРУЖАЕМ ИСХОДНИК ======
$src = imagecreatefromjpeg($sourceFile);
if (!$src) {
    return;
}

$srcW = imagesx($src);
$srcH = imagesy($src);
$srcTime = filemtime($sourceFile);

// ====== ФУНКЦИЯ COVER ======
function miResizeCover($src, $srcW, $srcH, $dstW, $dstH) {

    $srcRatio = $srcW / $srcH;
    $dstRatio = $dstW / $dstH;

    if ($srcRatio > $dstRatio) {
        $newH = $srcH;
        $newW = (int)($srcH * $dstRatio);
        $srcX = (int)(($srcW - $newW) / 2);
        $srcY = 0;
    } else {
        $newW = $srcW;
        $newH = (int)($srcW / $dstRatio);
        $srcX = 0;
        $srcY = (int)(($srcH - $newH) / 2);
    }

    $dst = imagecreatetruecolor($dstW, $dstH);
    imagecopyresampled(
        $dst, $src,
        0, 0,
        $srcX, $srcY,
        $dstW, $dstH,
        $newW, $newH
    );

    return $dst;
}

// ====== ГЕНЕРАЦИЯ РАЗМЕРОВ ======
foreach ($sizes as $suffix => [$w, $h]) {

    $target = $dir . '/' . $correctName . $suffix . '.' . $ext;

    $needRegenerate = true;
    if (file_exists($target)) {
        if (filemtime($target) >= $srcTime) {
            $needRegenerate = false;
        }
    }

    if (!$needRegenerate) {
        continue;
    }

    $dst = miResizeCover($src, $srcW, $srcH, $w, $h);
    imagejpeg($dst, $target, $quality);
    imagedestroy($dst);
}

imagedestroy($src);
