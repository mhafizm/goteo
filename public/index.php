<?php

/*
 * This file is part of the Goteo Package.
 *
 * (c) Fundación Goteo <fundacion@goteo.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\HttpFoundation\Request;
use Goteo\Application\App;
use Goteo\Application\Config;

//Public Web path
define('GOTEO_WEB_PATH', __DIR__ . '/');

require_once __DIR__ . '/../src/autoload.php';

// Create first the request object (to avoid other classes reading from php://input specially)
$request = Request::createFromGlobals();

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_USER_DEPRECATED); // for symfony user deprecated errors
// error handle needs to go after autoload
set_error_handler('Goteo\Application\App::errorHandler');

// Config file...
$config = getenv('GOTEO_CONFIG_FILE');
if(!is_file($config)) $config = __DIR__ . '/../config/settings.yml';
Config::load($config);
Config::autosave();

// Due a symfony issue, disable FORWARDED header, it may cause some problems
// if not exactly the same as the X_FORWARDED_FOR
// See https://stackoverflow.com/questions/44543649/conflict-between-http-headers-in-symfony-3
Request::setTrustedHeaderName(Request::HEADER_FORWARDED, null);

// Add trusted proxies
if (is_array(Config::get('proxies'))) {
    $request->setTrustedProxies(Config::get('proxies'));
}

//Get from globals defaults
App::setRequest($request);

// Get the app
$app = App::get();

// handle routes, flush buffer out
$app->run();

