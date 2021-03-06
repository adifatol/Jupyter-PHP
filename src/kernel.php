#!/usr/bin/env php
<?php

/*
 * This file is part of Jupyter-PHP.
 *
 * (c) 2015-2017 Litipk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Litipk\JupyterPHP;

define(
    'PATH_TO_VENDOR_AS_LIBRARY',
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
);

define(
    'PATH_TO_VENDOR_AS_PROJECT',
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor'
);

$pathToVendor = file_exists(PATH_TO_VENDOR_AS_LIBRARY)
    ? PATH_TO_VENDOR_AS_LIBRARY
    : PATH_TO_VENDOR_AS_PROJECT;

require ($pathToVendor . DIRECTORY_SEPARATOR . 'autoload.php');


use Litipk\JupyterPHP\System\System;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;


$system = System::getSystem();
$logger = new Logger('kernel');

$loggerActivationStrategy = new ErrorLevelActivationStrategy(LoggerSettings::getCrossFingersLevel());

if ('root' === $system->getCurrentUser()) {
    if (System::OS_LINUX === $system->getOperativeSystem()) {
        $logger->pushHandler(
            new FingersCrossedHandler(
                new GroupHandler([
                    new SyslogHandler('jupyter-php'),
                    new StreamHandler('php://stderr')
                ]),
                $loggerActivationStrategy,
                128
            )
        );
    }
} else {
    $system->ensurePath($system->getAppDataDirectory().'/logs');
    $logger->pushHandler(new FingersCrossedHandler(
        new GroupHandler([
            new RotatingFileHandler($system->getAppDataDirectory().'/logs/error.log', 7),
            new StreamHandler('php://stderr')
        ]),
        $loggerActivationStrategy,
        128
    ));
}


try {
    // Obtain settings
    $connectionSettings = ConnectionSettings::get();
    $connUris = ConnectionSettings::getConnectionUris($connectionSettings);

    $logger->debug('Connection settings', [
        'processId' => getmypid(),
        'connSettings' => $connectionSettings,
        'connUris' => $connUris
    ]);

    $kernelCore = new KernelCore(
        new JupyterBroker(
            $connectionSettings['key'],
            $connectionSettings['signature_scheme'],
            Uuid::uuid4(),
            $logger->withName('JupyterBroker')
        ),
        $connUris,
        $logger->withName('KernelCore')
    );

    $kernelCore->run();
} catch (\Exception $e) {
    $logger->error('Unexpected error', ['exception' => $e]);
}
