# Flysystem Adapter for Google Drive

[![Author](https://img.shields.io/badge/author-nao--pon%20hypweb-blue.svg?style=flat)](http://xoops.hypweb.net/)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)


## Installation

```bash
composer require nao-pon/flysystem-google-drive @dev
```

## Usage

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

## TODO

* Unit tests to be written
