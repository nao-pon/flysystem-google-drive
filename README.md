# Flysystem Adapter for Google Drive

This is forked package and the link to the original package is [here](https://github.com/nao-pon/flysystem-google-drive).

## What lead us to create yet another google drive adapter
The original package does not seem to be active with base google drive services(google/apiclient-services) update. The new update from drive seems to break the way ```getPermissions()``` method is used inside adapter package.

That's the only reason we created this package.

 **We did PR the original package to fix the issue and we have plan to abandon this package once our PR is merged.**

## Installation

```bash
composer require enston/flysystem-google-drive
```