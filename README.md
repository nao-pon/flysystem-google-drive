# Flysystem Adapter for Google Drive

[![Author](https://img.shields.io/badge/author-nao--pon%20hypweb-blue.svg?style=flat)](http://xoops.hypweb.net/)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)


## Installation

- For Google Drive API V2
```bash
composer require nao-pon/flysystem-google-drive:~1.0
```
- For Google Drive API V3 "**Recommended**"
```bash
composer require nao-pon/flysystem-google-drive:~1.1
```

## Usage
#### follow [Google Docs](https://developers.google.com/drive/v3/web/enable-sdk) to obtain your `ClientId, ClientSecret & refreshToken`
- you can also check [This Exmaple](https://github.com/nao-pon/flysystem-google-drive/blob/master/example/GoogleUpload.php) for a better understanding.

```php
$client = new \Google_Client();
$client->setClientId('[app client id].apps.googleusercontent.com');
$client->setClientSecret('[app client secret]');
$client->refreshToken('[your refresh token]');

$service = new \Google_Service_Drive($client);

$adapter = new \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter($service, '['root' or folder ID]');
/* Recommended cached adapter use */
// $adapter = new \League\Flysystem\Cached\CachedAdapter(
//     new \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter($service, '['root' or folder ID]'),
//     new \League\Flysystem\Cached\Storage\Memory()
// );

$filesystem = new \League\Flysystem\Filesystem($adapter);
```

### Usage to with [elFinder](https://github.com/Studio-42/elFinder)

```bash
composer require nao-pon/elfinder-flysystem-driver-ext
composer require nao-pon/flysystem-google-drive:~1.1
```

```php
// Load composer autoloader
require 'vender/autoload.php';

// Google API Client
$client = new \Google_Client();
$client->setClientId('xxxxx CLIENTID xxxxx');
$client->setClientSecret('xxxxx CLIENTSECRET xxxxx');
$client->refreshToken('xxxxx REFRESH TOKEN xxxxx');

// Google Drive Adapter
$googleDrive = new \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter(
	new \Google_Service_Drive($client), // Client service
	'root',                             // Holder ID as root ('root' or Holder ID)
	[ 'useHasDir' => true ]             // options (elFinder need hasDir method)
);

// Extended cached strage adapter class for cache enabled of hasDir() method
class myCachedStrageAdapter extends \League\Flysystem\Cached\Storage\Adapter
{
    use \Hypweb\Flysystem\Cached\Extra\Hasdir;
    use \Hypweb\Flysystem\Cached\Extra\DisableEnsureParentDirectories;
}

// Make Flysystem adapter and cache object
$useCache = true;
if ($useCache) {
	// Example to Flysystem cacheing
	$cache = new myCachedStrageAdapter(
		new \League\Flysystem\Adapter\Local('flycache'),
		'gdcache',
		300
	);

	// Flysystem cached adapter
	$adapter = new \League\Flysystem\Cached\CachedAdapter(
		$googleDrive,
		$cache
	);
} else {
	// Not use cached adapter
	$cache = null;
	$adapter = $googleDrive;
}

// Google Drive elFinder Volume driver
$gdrive = [
    // require
    'driver'       => 'FlysystemExt',
    'filesystem'   =>  new \League\Flysystem\Filesystem($adapter),
    'fscache'      => $cache,
    'separator'    => '/',
    // optional
    'alias'        => 'GoogleDrive',
    'rootCssClass' => 'elfinder-navbar-root-googledrive'
];

// elFinder volume roots options
$elFinderOpts = [
	'roots' => []
];

$elFinderOpts['roots'][] = $gdrive;

// run elFinder
$connector = new elFinderConnector(new elFinder($elFinderOpts));
$connector->run();
```

## TODO

* Unit tests to be written
