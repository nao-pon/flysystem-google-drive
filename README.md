# Flysystem Adapter for Google Drive

This is forked package and the link to the original package is [here.](https://github.com/nao-pon/flysystem-google-drive).

## What lead use to create yet another google drive adapter
The original package is not seem to be active with base google drive services update. The new update from drive seems to break the way ```getPermissions()``` method work. 

That's the only reason we create this package.

 **We did PR to the original package to fix the issue and we have planned to abandon this package once our PR is merged.**



## Installation

```bash
composer require enston/flysystem-google-drive
```