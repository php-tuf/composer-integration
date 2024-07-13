<?php

$file = __DIR__ . $_SERVER['SCRIPT_NAME'];

if (!file_exists($file)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

if (array_key_exists('HTTP_IF_MODIFIED_SINCE', $_SERVER)) {
    $modifiedSince = new \DateTimeImmutable($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    $modifiedAt = @filemtime($file);
} else {
    $modifiedSince = false;
    $modifiedAt = false;
}

if ($modifiedSince && $modifiedAt && $modifiedAt <= $modifiedSince->getTimestamp()) {
    header('HTTP/1.1 304 Not Modified');
    exit;
}

header('HTTP/1.1 200 Found');
echo file_get_contents($file);
