<?php
/**
 * MixedImageAutoResize
 * MODX 2.8.8
 */

// Проверка - если ресурс удален то удаляем и его картинки
if ($modx->event->name === 'OnBeforeEmptyTrash') {

    $ids = $modx->event->params['ids'] ?? [];
    if (empty($ids)) {
        return;
    }

    $tvName = 'image';
    $basePath = $modx->getOption('base_path');

    /** @var modTemplateVar $tv */
    $tv = $modx->getObject('modTemplateVar', ['name' => $tvName]);
    if (!$tv) {
        return;
    }

    foreach ($ids as $id) {

        /** @var modResource $res */
        $res = $modx->getObject('modResource', $id);
        if (!$res) {
            continue;
        }

        $image = $res->getTVValue($tvName);
        if (empty($image)) {
            continue;
        }

        $source = $basePath . ltrim($image, '/');
        if (!file_exists($source)) {
            continue;
        }

        $info = pathinfo($source);
        $dir  = $info['dirname'];
        $name = $info['filename'];
        $ext  = $info['extension'];

        // основной файл
        @unlink($dir . '/' . $name . '.' . $ext);

        // размеры
        @unlink($dir . '/' . $name . '-L.' . $ext);
        @unlink($dir . '/' . $name . '-S.' . $ext);
    }

    return;
}


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
$tvName  = 'YurAdrMainPic'; // имя TV MixedImage
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

if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
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
switch ($ext) {
    case 'jpg':
    case 'jpeg':
        $src = imagecreatefromjpeg($sourceFile);
        break;

    case 'png':
        $src = imagecreatefrompng($sourceFile);
        break;

    default:
        return;
}

if (!$src) {
    return;
}

$srcW = imagesx($src);
$srcH = imagesy($src);
$srcTime = filemtime($sourceFile);

// ====== ФУНКЦИЯ COVER ======
function miResizeCover($src, $srcW, $srcH, $dstW, $dstH, $ext) {

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
    
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
    }
    
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

    $dst = miResizeCover($src, $srcW, $srcH, $w, $h, $ext);
    if ($ext === 'png') {
    imagepng($dst, $target, 6); // 0–9, 6 = оптимум
    } else {
        imagejpeg($dst, $target, $quality);
    }
    imagedestroy($dst);
}

imagedestroy($src);
