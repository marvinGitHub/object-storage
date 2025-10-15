<?php
/**
 * Default configuration for PHP Profiler.
 *
 * To change these, create a file called `config.php` file in the same directory
 * and return an array from there with your overriding settings.
 */

use Xhgui\Profiler\Profiler;
use Xhgui\Profiler\ProfilingFlags;

return [
    'save.handler' => Profiler::SAVER_UPLOAD,
    'save.handler.stack' => [
        'savers' => [
            Profiler::SAVER_UPLOAD,
            Profiler::SAVER_FILE,
        ],
        'saveAll' => false,
    ],
    'save.handler.upload' => [
        'url' => 'https://172.16.50.10:7777/xhgui/webroot/run/import',
        // The timeout option is in seconds and defaults to 3 if unspecified.
        'timeout' => 3,
        // the token must match 'upload.token' config in XHGui
        'token' => 'ThisIsNotARealToken',
        // verify option to disable ssl verification, defaults to true if unspecified.
        'verify' => false,
    ],
    'save.handler.file' => [
        'filename' => sys_get_temp_dir() . '/xhgui.data.jsonl',
    ],
    'profiler.enable' => function () {
        return true;
    },
    'profiler.flags' => [
        ProfilingFlags::CPU,
        ProfilingFlags::MEMORY
    ],
    'profiler.options' => [],
    'profiler.exclude-env' => [],
    'profiler.simple_url' => function ($url) {
        return preg_replace('/=\d+/', '', $url);
    },
    'profiler.replace_url' => null,
];
