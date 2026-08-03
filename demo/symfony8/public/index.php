<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

// In Docker dev: app listens on 80 but is exposed as PORT on the host; set X-Forwarded-Port
// so the Web Profiler toolbar and generated URLs use the correct port (e.g. 8011).
if (($_SERVER['APP_ENV'] ?? '') === 'dev'
    && ($port = getenv('PORT')) !== false
    && ($_SERVER['SERVER_PORT'] ?? '80') === '80') {
    $_SERVER['HTTP_X_FORWARDED_PORT'] = $port;
}

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
