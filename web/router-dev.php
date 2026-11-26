<?php
/** Yerel geliştirme yönlendiricisi:  php -S 127.0.0.1:8080 -t web web/router-dev.php */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(includes|install|config|storage|lang)(/|$)#', $path)) {
    http_response_code(403);
    return true;
}
if (preg_match('#^/api/(v\d+/.*)$#', $path, $m)) {
    $_GET['r'] = '/' . $m[1];
    require __DIR__ . '/api/index.php';
    return true;
}
if ($path !== '/' && is_file(__DIR__ . $path) && !str_ends_with($path, '.php')) {
    return false;
}
if (is_dir(__DIR__ . $path) && is_file(rtrim(__DIR__ . $path, '/') . '/index.php')) {
    require rtrim(__DIR__ . $path, '/') . '/index.php';
    return true;
}
return false;
