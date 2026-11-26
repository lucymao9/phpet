<?php

use phpet\core\Application;

require dirname(__DIR__) . '/bootstrap/autoload.php';

// 配置在 config/app.php，与 bin/console 共用同一份
(new Application(require dirname(__DIR__) . '/config/app.php'))->run();
