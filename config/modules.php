<?php

declare(strict_types=1);

use bapersbw\Modules\Login\LoginWebservice;
use bapersbw\Modules\Users\UsersWebservice;

/**
 * Module map: the first URL segment => the webservice class that owns it.
 *
 * Adding a module is a one-liner here plus a class extending
 * {@see bapersbw\Webservice\Webservice}. This is the heart of expandability.
 */
return [
    'users' => UsersWebservice::class,
    'login' => LoginWebservice::class,
];
