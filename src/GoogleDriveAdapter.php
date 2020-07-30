<?php
namespace Hypweb\Flysystem\GoogleDrive;

use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Service_Drive_Permission;
use Google_Http_MediaFileUpload;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class GoogleDriveAdapter extends AbstractAdapter
{

    /**
     * Fetch fields setting for get
     *
     * @var string
     */
    const FETCHFIELDS_GET = 'id,name,mimeType,modifiedTime,parents,permissions,size,webContentLink,webViewLink';

    /**
     * Fetch fields setting for list
     *
     * @var string
     */
    const FETCHFIELDS_LIST = 'files(FETCHFIELDS_GET),nextPageToken';

    /**
     * MIME tyoe of directory
     *
     * @var string
     */
    const DIRMIME = 'application/vnd.google-apps.folder';

    /**
     * Google_Service_Drive instance
     *
     * @var Google_Service_Drive
     */
    protected $service;

    /**
     * Default options
     *
     * @var array
     */
    protected static $defaultOptions = [
        'spaces' => 'drive',
        'useHasDir' => false,
        'additionalFetchField' => '',
        'publishPermission' => [
            'type' => 'anyone',
            'role' => 'reader',
            'withLink' => true
        ],
        'appsExportMap' => [
            'application/vnd.google-apps.document' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.google-apps.spreadsheet' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.google-apps.drawing' => 'application/pdf',
            'application/vnd.google-apps.presentation' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.google-apps.script' => 'application/vnd.google-apps.script+json',
            'default' => 'application/pdf'
        ],
        // Default parameters for each command
        // see https://developers.google.com/drive/v3/reference/files
        // ex. 'defaultParams' => ['files.list' => ['includeTeamDriveItems' => true]]
        'defaultParams' => [],
        // Team Drive Id
        'teamDriveId' => null,
        // Corpora value for files.list with the Team Drive
        'corpora' => 'teamDrive',
        // Delete action 'trash' (Into trash) or 'delete' (Permanently delete)
        'deleteAction' => 'trash'
    ];

    /**
     * A comma-separated list of spaces to query
     * Supported values are 'drive', 'appDataFolder' and 'photos'
     *
     * @var string
     */
    protected $spaces;

    /**
     * Permission array as published item
     *
     * @var array
     */
    protected $publishPermission;

    /**
     * Cache of file objects
     *
     * @var array
     */
    private $cacheFileObjects = [];

    /**
     * Cache of file objects by ParentId/Name based
     *
     * @var array
     */
    private $cacheFileObjectsByName = [];

    /**
     * Cache of hasDir
     *
     * @var array
     */
    private $cacheHasDirs = [];

    /**
     * Use hasDir function
     *
     * @var bool
     */
    private $useHasDir = false;

    /**
     * List of fetch field for get
     *
     * @var string
     */
    private $fetchfieldsGet = '';

    /**
     * List of fetch field for lest
     *
     * @var string
     */
    private $fetchfieldsList = '';

    /**
     * Additional fetch fields array
     *
     * @var array
     */
    private $additionalFields = [];

    /**
     * Options array
     *
     * @var array
     */
    private $options = [];

    /**
     * Default parameters of each commands
     *
     * @var array
     */
    private $defaultParams = [];

    public function __construct(Google_Service_Drive $service, $root = null, $options = [])
    {
        if (! $root) {
            $root = 'root';
        }
        $this->service = $service;
        $this->setPathPrefix($root);
        $this->root = $root;

        $this->options = array_replace_recursive(static::$defaultOptions, $options);

        $this->spaces = $this->options['spaces'];
        $this->useHasDir = $this->options['useHasDir'];
        $this->publishPermission = $this->options['publishPermission'];

        $this->fetchfieldsGet = self::FETCHFIELDS_GET;
        if ($this->options['additionalFetchField']) {
             $this->fetchfieldsGet .= ',' . $this->options['additionalFetchField'];
             $this->additionalFields = explode(',', $this->options['additionalFetchField']);
        }
        $this->fetchfieldsList = str_replace('FETCHFIELDS_GET', $this->fetchfieldsGet, self::FETCHFIELDS_LIST);
        if (isset($this->options['defaultParams']) && is_array($this->options['defaultParams'])) {
            $this->defaultParams = $this->options['defaultParams'];
        }

        if ($this->options['teamDriveId']) {
            $this->setTeamDriveId($this->options['teamDriveId'], $this->options['corpora']);
        }
    }

    /**
     * Gets the service (Google_Service_Drive)
     *
     * @return object  Google_Service_Drive
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     *            Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config
     *            Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     *            Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config
     *            Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        list ($oldParent, $fileId) = $this->splitPath($path);
        list ($newParent, $newName) = $this->splitPath($newpath);

        $file = new Google_Service_Drive_DriveFile();
        $file->setName($newName);
        $opts = [
            'fields' => $this->fetchfieldsGet
        ];
        if ($newParent !== $oldParent) {
            $opts['addParents'] = $newParent;
            $opts['removeParents'] = $oldParent;
        }

        $updatedFile = $this->service->files->update($fileId, $file, $this->applyDefaultParams($opts, 'files.update'));

        if ($updatedFile) {
            $this->cacheFileObjects[$updatedFile->getId()] = $updatedFile;
            $this->cacheFileObjectsByName[$newParent . '/' . $newName] = $updatedFile;
            return true;
        }

        return false;
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        list (, $srcId) = $this->splitPath($path);

        list ($newParentId, $fileName) = $this->splitPath($newpath);

        $file = new Google_Service_Drive_DriveFile();
        $file->setName($fileName);
        $file->setParents([
            $newParentId
        ]);

        $newFile = $this->service->files->copy($srcId, $file, $this->applyDefaultParams([
            'fields' => $this->fetchfieldsGet
        ], 'files.copy'));

        if ($newFile instanceof Google_Service_Drive_DriveFile) {
            $this->cacheFileObjects[$newFile->getId()] = $newFile;
            $this->cacheFileObjectsByName[$newParentId . '/' . $fileName] = $newFile;
            list ($newDir) = $this->splitPath($newpath);
            $newpath = (($newDir === $this->root) ? '' : ($newDir . '/')) . $newFile->getId();
            if ($this->getRawVisibility($path) === AdapterInterface::VISIBILITY_PUBLIC) {
                $this->publish($newpath);
            } else {
                $this->unPublish($newpath);
            }
            return true;
        }

        return false;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        if ($file = $this->getFileObject($path)) {
            $name = $file->getName();
            list ($parentId, $id) = $this->splitPath($path);
            if ($parents = $file->getParents()) {
                $file = new Google_Service_Drive_DriveFile();
                $opts = [];
                $res = false;
                if (count($parents) > 1) {
                    $opts['removeParents'] = $parentId;
                } else {
                    if ($this->options['deleteAction'] === 'delete') {
                        try {
                            $this->service->files->delete($id);
                        } catch (Google_Exception $e) {
                            return false;
                        }
                        $res = true;
                    } else {
                        $file->setTrashed(true);
                    }
                }
                if (!$res) {
                    try {
                        $this->service->files->update($id, $file, $this->applyDefaultParams($opts, 'files.update'));
                    } catch (Google_Exception $e) {
                        return false;
                    }
                }
                unset($this->cacheFileObjects[$id], $this->cacheHasDirs[$id], $this->cacheFileObjectsByName[$parentId . '/' . $name]);
                return true;
            }
        }
        return false;
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    /**
     * Create a directory.
     *
     * @param string $dirname
     *            directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        list ($pdirId, $name) = $this->splitPath($dirname);

        $folder = $this->createDirectory($name, $pdirId);
        if ($folder) {
            $itemId = $folder->getId();
            $this->cacheFileObjectsByName[$pdirId . '/' . $name] = $folder; // for confirmation by getMetaData() oe has() while in this connection
            $this->cacheFileObjects[$itemId] = $folder;
            $this->cacheHasDirs[$itemId] = false;
            $path_parts = $this->splitFileExtension($name);
            $result = [
                'path' => Util::dirname($dirname) . '/' . $itemId,
                'filename' => $path_parts['filename'],
                'extension' => $path_parts['extension']
            ];
            return $result;
        }

        return false;
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        return ($this->getFileObject($path, true) instanceof Google_Service_Drive_DriveFile);
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        list (, $fileId) = $this->splitPath($path);
        if ($response = $this->service->files->get($fileId, $this->applyDefaultParams([
            'alt' => 'media'
        ], 'files.get'))) {
            return [
                'contents' => (string) $response->getBody()
            ];
        }

        return false;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $redirect = [];
        if (func_num_args() > 1) {
            $redirect = func_get_arg(1);
        }
        if (! $redirect) {
            $redirect = [
                'cnt' => 0,
                'url' => '',
                'token' => '',
                'cookies' => []
            ];
            if ($file = $this->getFileObject($path)) {
                $dlurl = $this->getDownloadUrl($file);
                $client = $this->service->getClient();
                if ($client->isUsingApplicationDefaultCredentials()) {
                    $token = $client->fetchAccessTokenWithAssertion();
                } else {
                    $token = $client->getAccessToken();
                }
                $access_token = '';
                if (is_array($token)) {
                    if (empty($token['access_token']) && !empty($token['refresh_token'])) {
                        $token = $client->fetchAccessTokenWithRefreshToken();
                    }
                    $access_token = $token['access_token'];
                } else {
                    if ($token = @json_decode($client->getAccessToken())) {
                        $access_token = $token->access_token;
                    }
                }
                $redirect = [
                    'cnt' => 0,
                    'url' => '',
                    'token' => $access_token,
                    'cookies' => []
                ];
            }
        } else {
            if ($redirect['cnt'] > 5) {
                return false;
            }
            $dlurl = $redirect['url'];
            $redirect['url'] = '';
            $access_token = $redirect['token'];
        }

        if ($dlurl) {
            $url = parse_url($dlurl);
            $cookies = [];
            if ($redirect['cookies']) {
                foreach ($redirect['cookies'] as $d => $c) {
                    if (strpos($url['host'], $d) !== false) {
                        $cookies[] = $c;
                    }
                }
            }
            if ($access_token) {
                $query = isset($url['query']) ? '?' . $url['query'] : '';
                $stream = stream_socket_client('ssl://' . $url['host'] . ':443');
                stream_set_timeout($stream, 300);
                fputs($stream, "GET {$url['path']}{$query} HTTP/1.1\r\n");
                fputs($stream, "Host: {$url['host']}\r\n");
                fputs($stream, "Authorization: Bearer {$access_token}\r\n");
                fputs($stream, "Connection: Close\r\n");
                if ($cookies) {
                    fputs($stream, "Cookie: " . join('; ', $cookies) . "\r\n");
                }
                fputs($stream, "\r\n");
                while (($res = trim(fgets($stream))) !== '') {
                    // find redirect
                    if (preg_match('/^Location: (.+)$/', $res, $m)) {
                        $redirect['url'] = $m[1];
                    }
                    // fetch cookie
                    if (strpos($res, 'Set-Cookie:') === 0) {
                        $domain = $url['host'];
                        if (preg_match('/^Set-Cookie:(.+)(?:domain=\s*([^ ;]+))?/i', $res, $c1)) {
                            if (! empty($c1[2])) {
                                $domain = trim($c1[2]);
                            }
                            if (preg_match('/([^ ]+=[^;]+)/', $c1[1], $c2)) {
                                $redirect['cookies'][$domain] = $c2[1];
                            }
                        }
                    }
                }
                if ($redirect['url']) {
                    $redirect['cnt'] ++;
                    fclose($stream);
                    return $this->readStream($path, $redirect);
                }
                return compact('stream');
            }
        }
        return false;
    }

    /**
     * List contents of a directory.
     *
     * @param string $dirname
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($dirname = '', $recursive = false)
    {
        return $this->getItems($dirname, $recursive);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        if ($obj = $this->getFileObject($path, true)) {
            if ($obj instanceof Google_Service_Drive_DriveFile) {
                return $this->normaliseObject($obj, Util::dirname($path));
            }
        }
        return false;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['size'])) ? $meta : false;
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['mimetype'])) ? $meta : false;
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['timestamp'])) ? $meta : false;
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $result = ($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? $this->publish($path) : $this->unPublish($path);

        if ($result) {
            return compact('path', 'visibility');
        }

        return false;
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        return [
            'visibility' => $this->getRawVisibility($path)
        ];
    }

    // /////////////////- ORIGINAL METHODS -///////////////////

    /**
     * Get contents parmanent URL
     *
     * @param string $path
     *            itemId path
     *
     * @return string|false
     */
    public function getUrl($path)
    {
        if ($this->publish($path)) {
            $obj = $this->getFileObject($path);
            if ($url = $obj->getWebContentLink()) {
                return str_replace('export=download', 'export=media', $url);
            }
            if ($url = $obj->getWebViewLink()) {
                return $url;
            }
        }
        return false;
    }

    /**
     * Has child directory
     *
     * @param string $path
     *            itemId path
     *
     * @return array
     */
    public function hasDir($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['hasdir'])) ? $meta : [
            'hasdir' => true
        ];
    }

    /**
     * Do cache cacheHasDirs
     *
     * @param array $targets
     *            [[path => id],...]
     *
     * @return void
     */
    protected function setHasDir($targets, $object)
    {
        $service = $this->service;
        $client = $service->getClient();
        $gFiles = $service->files;
        $opts = [
            'pageSize' => 1
        ];
        $paths = [];
        $results = [];
        $i = 0;
        foreach ($targets as $id) {
            $opts['q'] = sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $id, self::DIRMIME);
            $request = $gFiles->listFiles($this->applyDefaultParams($opts, 'files.list'));
            $key = (string) ++ $i;
            $results[$key] = $request;
            $paths[$key] = $id;
        }
        foreach ($results as $key => $result) {
            if ($result instanceof Google_Service_Drive_FileList) {
                $object[$paths[$key]]['hasdir'] = $this->cacheHasDirs[$paths[$key]] = (bool) $result->getFiles();
            }
        }
        return $object;
    }

    /**
     * Get the object permissions presented as a visibility.
     *
     * @param string $path
     *            itemId path
     *
     * @return string
     */
    protected function getRawVisibility($path)
    {
        $file = $this->getFileObject($path);
        $permissions = $file->getPermissions();
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;
        foreach ($permissions as $permission) {
            if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;
                break;
            }
        }
        return $visibility;
    }

    /**
     * Publish specified path item
     *
     * @param string $path
     *            itemId path
     *
     * @return bool
     */
    protected function publish($path)
    {
        if (($file = $this->getFileObject($path))) {
            if ($this->getRawVisibility($path) === AdapterInterface::VISIBILITY_PUBLIC) {
                return true;
            }
            try {
                $permission = new Google_Service_Drive_Permission($this->publishPermission);
                if ($this->service->permissions->create($file->getId(), $permission)) {
                    return true;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Un-publish specified path item
     *
     * @param string $path
     *            itemId path
     *
     * @return bool
     */
    protected function unPublish($path)
    {
        if (($file = $this->getFileObject($path))) {
            $permissions = $file->getPermissions();
            try {
                foreach ($permissions as $permission) {
                    if ($permission->type === 'anyone' && $permission->role === 'reader') {
                        $this->service->permissions->delete($file->getId(), $permission->getId());
                    }
                }
                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Path splits to dirId, fileId or newName
     *
     * @param string $path
     *
     * @return array [ $dirId , $fileId|newName ]
     */
    protected function splitPath($path, $getParentId = true)
    {
        $useSlashSub = defined('EXT_FLYSYSTEM_SLASH_SUBSTITUTE');
        if ($path === '' || $path === '/') {
            $fileName = $this->root;
            $dirName = '';
        } else {
            if ($useSlashSub) {
                $path = str_replace(EXT_FLYSYSTEM_SLASH_SUBSTITUTE, chr(7), $path);
            }
            $paths = explode('/', $path);
            $fileName = array_pop($paths);
            if ($getParentId) {
                $dirName = $paths ? array_pop($paths) : '';
            } else {
                $dirName = join('/', $paths);
            }
            if ($dirName === '') {
                $dirName = $this->root;
            }
        }
        return [
            $dirName,
            $useSlashSub? str_replace(chr(7), '/', $fileName) : $fileName
        ];
    }

    /**
     * Item name splits to filename and extension
     * This function supported include '/' in item name
     *
     * @param string $name
     *
     * @return array [ 'filename' => $filename , 'extension' => $extension ]
     */
    protected function splitFileExtension($name)
    {
        $extension = '';
        $name_parts = explode('.', $name);
        if (isset($name_parts[1])) {
            $extension = array_pop($name_parts);
        }
        $filename = join('.', $name_parts);
        return compact('filename', 'extension');
    }

    /**
     * Get normalised files array from Google_Service_Drive_DriveFile
     *
     * @param Google_Service_Drive_DriveFile $object
     * @param String $dirname
     *            Parent directory itemId path
     *
     * @return array Normalised files array
     */
    protected function normaliseObject(Google_Service_Drive_DriveFile $object, $dirname)
    {
        $id = $object->getId();
        $path_parts = $this->splitFileExtension($object->getName());
        $result = ['name' => $object->getName()];
        $result['type'] = $object->mimeType === self::DIRMIME ? 'dir' : 'file';
        $result['path'] = ($dirname ? ($dirname . '/') : '') . $id;
        $result['filename'] = $path_parts['filename'];
        $result['extension'] = $path_parts['extension'];
        $result['timestamp'] = strtotime($object->getModifiedTime());
        if ($result['type'] === 'file') {
            $result['mimetype'] = $object->mimeType;
            $result['size'] = (int) $object->getSize();
        }
        if ($result['type'] === 'dir') {
            $result['size'] = 0;
            if ($this->useHasDir) {
                $result['hasdir'] = isset($this->cacheHasDirs[$id]) ? $this->cacheHasDirs[$id] : false;
            }
        }
        // attach additional fields
        if ($this->additionalFields) {
            foreach($this->additionalFields as $field) {
                if (property_exists($object, $field)) {
                    $result[$field] = $object->$field;
                }
            }
        }
        return $result;
    }

    /**
     * Get items array of target dirctory
     *
     * @param string $dirname
     *            itemId path
     * @param bool $recursive
     * @param number $maxResults
     * @param string $query
     *
     * @return array Items array
     */
    protected function getItems($dirname, $recursive = false, $maxResults = 0, $query = '')
    {
        list (, $itemId) = $this->splitPath($dirname);

        $maxResults = min($maxResults, 1000);
        $results = [];
        $parameters = [
            'pageSize' => $maxResults ?: 1000,
            'fields' => $this->fetchfieldsList,
            'spaces' => $this->spaces,
            'q' => sprintf('trashed = false and "%s" in parents', $itemId)
        ];
        if ($query) {
            $parameters['q'] .= ' and (' . $query . ')';
        }
        $parameters = $this->applyDefaultParams($parameters, 'files.list');
        $pageToken = NULL;
        $gFiles = $this->service->files;
        $this->cacheHasDirs[$itemId] = false;
        $setHasDir = [];

        do {
            try {
                if ($pageToken) {
                    $parameters['pageToken'] = $pageToken;
                }
                $fileObjs = $gFiles->listFiles($parameters);
                if ($fileObjs instanceof Google_Service_Drive_FileList) {
                    foreach ($fileObjs as $obj) {
                        $id = $obj->getId();
                        $this->cacheFileObjects[$id] = $obj;
                        $result = $this->normaliseObject($obj, $dirname);
                        $results[$id] = $result;
                        if ($result['type'] === 'dir') {
                            if ($this->useHasDir) {
                                $setHasDir[$id] = $id;
                            }
                            if ($this->cacheHasDirs[$itemId] === false) {
                                $this->cacheHasDirs[$itemId] = true;
                                unset($setHasDir[$itemId]);
                            }
                            if ($recursive) {
                                $results = array_merge($results, $this->getItems($result['path'], true, $maxResults, $query));
                            }
                        }
                    }
                    $pageToken = $fileObjs->getNextPageToken();
                } else {
                    $pageToken = NULL;
                }
            } catch (Exception $e) {
                $pageToken = NULL;
            }
        } while ($pageToken && $maxResults === 0);

        if ($setHasDir) {
            $results = $this->setHasDir($setHasDir, $results);
        }
        return array_values($results);
    }

    /**
     * Get file oblect Google_Service_Drive_DriveFile
     *
     * @param string $path
     *            itemId path
     * @param string $checkDir
     *            do check hasdir
     *
     * @return Google_Service_Drive_DriveFile|null
     */
    protected function getFileObject($path, $checkDir = false)
    {
        list ($parentId, $itemId) = $this->splitPath($path, true);
        if (isset($this->cacheFileObjects[$itemId])) {
            return $this->cacheFileObjects[$itemId];
        } else if (isset($this->cacheFileObjectsByName[$parentId . '/' . $itemId])) {
            return $this->cacheFileObjectsByName[$parentId . '/' . $itemId];
        }

        $service = $this->service;
        $client = $service->getClient();

        $fileObj = $hasdir = NULL;

        $opts = [
            'fields' => $this->fetchfieldsGet
        ];

        try {
            $fileObj = $this->service->files->get($itemId, $this->applyDefaultParams($opts, 'files.get'));
            if ($checkDir && $this->useHasDir) {
                $hasdir = $service->files->listFiles($this->applyDefaultParams([
                    'pageSize' => 1,
                    'q' => sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $itemId, self::DIRMIME)
                ], 'files.list'));
            }
        } catch (\Google_Service_Exception $e) {
            if (!$fileObj) {
                if (intVal($e->getCode()) != 404) {
                    return NULL;
                }
            }
        }

        if ($fileObj instanceof Google_Service_Drive_DriveFile) {
            if ($hasdir && $fileObj->mimeType === self::DIRMIME) {
                if ($hasdir instanceof Google_Service_Drive_FileList) {
                    $this->cacheHasDirs[$fileObj->getId()] = (bool) $hasdir->getFiles();
                }
            }
        } else {
            $fileObj = NULL;
        }
        $this->cacheFileObjects[$itemId] = $fileObj;

        return $fileObj;
    }

    /**
     * Get download url
     *
     * @param Google_Service_Drive_DriveFile $file
     *
     * @return string|false
     */
    protected function getDownloadUrl($file)
    {
        if (strpos($file->mimeType, 'application/vnd.google-apps') !== 0) {
            return 'https://www.googleapis.com/drive/v3/files/' . $file->getId() . '?alt=media';
        } else {
            $mimeMap = $this->options['appsExportMap'];
            if (isset($mimeMap[$file->getMimeType()])) {
                $mime = $mimeMap[$file->getMimeType()];
            } else {
                $mime = $mimeMap['default'];
            }
            $mime = rawurlencode($mime);

            return 'https://www.googleapis.com/drive/v3/files/' . $file->getId() . '/export?mimeType=' . $mime;
        }

        return false;
    }

    /**
     * Create dirctory
     *
     * @param string $name
     * @param string $parentId
     *
     * @return Google_Service_Drive_DriveFile|NULL
     */
    protected function createDirectory($name, $parentId)
    {
        $file = new Google_Service_Drive_DriveFile();
        $file->setName($name);
        $file->setParents([
            $parentId
        ]);
        $file->setMimeType(self::DIRMIME);

        $obj = $this->service->files->create($file, $this->applyDefaultParams([
            'fields' => $this->fetchfieldsGet
        ], 'files.create'));

        return ($obj instanceof Google_Service_Drive_DriveFile) ? $obj : false;
    }

    /**
     * Upload|Update item
     *
     * @param string $path
     * @param string|resource $contents
     * @param Config $config
     *
     * @return array|false item info array
     */
    protected function upload($path, $contents, Config $config)
    {
        list ($parentId, $fileName) = $this->splitPath($path);
        $srcDriveFile = $this->getFileObject($path);
        if (is_resource($contents)) {
            $uploadedDriveFile = $this->uploadResourceToGoogleDrive($contents, $parentId, $fileName, $srcDriveFile, $config->get('mimetype'));
        } else {
            $uploadedDriveFile = $this->uploadStringToGoogleDrive($contents, $parentId, $fileName, $srcDriveFile, $config->get('mimetype'));
        }

        return $this->normaliseUploadedFile($uploadedDriveFile, $path, $config->get('visibility'));
    }

    /**
     * Detect the largest chunk size that can be used for uploading a file
     *
     * @return int
     */
    protected function detectChunkSizeBytes()
    {
        // Max and default chunk size of 100MB
        $chunkSizeBytes = 100 * 1024 * 1024;
        $memoryLimit = $this->getIniBytes('memory_limit');
        if ($memoryLimit > 0) {
            $availableMemory = $memoryLimit - $this->getMemoryUsedBytes();
            /*
             * We need some breathing room, so we only take 1/4th of the available memory for use in chunking (the divide by 4 does this).
             * The chunk size must be a multiple of 256KB(262144).
             * An example of why we need the breathing room is detecting the mime type for a file that is just small enough to fit into one chunk.
             * In this scenario, we send the entire file off as a string to have the mime type detected. Unfortunately, this leads to the entire
             * file being loaded into memory again, separately from the copy we're holding.
             */
            $chunkSizeBytes = max(262144, min($chunkSizeBytes, floor($availableMemory / 4 / 262144) * 262144));
        }

        return (int)$chunkSizeBytes;
    }

    /**
     * Normalise a Drive File that has been created
     *
     * @param Google_Service_Drive_DriveFile $uploadedFile
     * @param string $localPath
     * @param string $visibility
     * @return array|bool
     */
    protected function normaliseUploadedFile($uploadedFile, $localPath, $visibility)
    {
        list ($parentId, $fileName) = $this->splitPath($localPath);

        if (!($uploadedFile instanceof Google_Service_Drive_DriveFile)) {
            return false;
        }

        $this->cacheFileObjects[$uploadedFile->getId()] = $uploadedFile;
        if (! $this->getFileObject($localPath)) {
            $this->cacheFileObjectsByName[$parentId . '/' . $fileName] = $uploadedFile;
        }
        $result = $this->normaliseObject($uploadedFile, Util::dirname($localPath));

        if ($visibility && $this->setVisibility($localPath, $visibility)) {
            $result['visibility'] = $visibility;
        }

        return $result;
    }

    /**
     * Upload a PHP resource stream to Google Drive
     *
     * @param resource $resource
     * @param string $parentId
     * @param string $fileName
     * @param string $mime
     * @return bool|Google_Service_Drive_DriveFile
     */
    protected function uploadResourceToGoogleDrive($resource, $parentId, $fileName, $srcDriveFile, $mime)
    {
        $chunkSizeBytes = $this->detectChunkSizeBytes();
        $fileSize = $this->getFileSizeBytes($resource);

        if ($fileSize <= $chunkSizeBytes) {
            // If the resource fits in a single chunk, we'll just upload it in a single request
            return $this->uploadStringToGoogleDrive(stream_get_contents($resource), $parentId, $fileName, $srcDriveFile, $mime);
        }

        $client = $this->service->getClient();
        // Call the API with the media upload, defer so it doesn't immediately return.
        $client->setDefer(true);
        $request = $this->ensureDriveFileExists('', $parentId, $fileName, $srcDriveFile, $mime);
        $client->setDefer(false);
        $media = $this->getMediaFileUpload($client, $request, $mime, $chunkSizeBytes);
        $media->setFileSize($fileSize);

        // Upload chunks until we run out of file to upload; $status will be false until the process is complete.
        $status = false;
        while (! $status && ! feof($resource)) {
            $chunk = $this->readFileChunk($resource, $chunkSizeBytes);
            $status = $media->nextChunk($chunk);
        }

        // The final value of $status will be the data from the API for the object that has been uploaded.
        return $status;
    }

    /**
     * Upload a string to Google Drive
     *
     * @param string $contents
     * @param string $parentId
     * @param string $fileName
     * @param string $mime
     * @return Google_Service_Drive_DriveFile
     */
    protected function uploadStringToGoogleDrive($contents, $parentId, $fileName, $srcDriveFile, $mime)
    {
        return $this->ensureDriveFileExists($contents, $parentId, $fileName, $srcDriveFile, $mime);
    }

    /**
     * Ensure that a file exists on Google Drive by creating it if it doesn't exist or updating it if it does
     *
     * @param string $contents
     * @param string $parentId
     * @param string $fileName
     * @param string $mime
     * @return Google_Service_Drive_DriveFile
     */
    protected function ensureDriveFileExists($contents, $parentId, $fileName, $srcDriveFile, $mime)
    {
        if (! $mime) {
            $mime = Util::guessMimeType($fileName, $contents);
        }

        $driveFile = new Google_Service_Drive_DriveFile();

        $mode = 'update';
        if (! $srcDriveFile) {
            $mode = 'insert';
            $driveFile->setName($fileName);
            $driveFile->setParents([$parentId]);
        }

        $driveFile->setMimeType($mime);

        $params = ['fields' => $this->fetchfieldsGet];
        if ($contents) {
            $params['data'] = $contents;
            $params['uploadType'] = 'media';
        }
        if ($mode === 'insert') {
            $retrievedDriveFile = $this->service->files->create($driveFile, $this->applyDefaultParams($params, 'files.create'));
        } else {
            $retrievedDriveFile = $this->service->files->update(
                $srcDriveFile->getId(),
                $driveFile,
                $this->applyDefaultParams($params, 'files.update')
            );
        }

        return $retrievedDriveFile;
    }

    /**
     * Read file chunk
     *
     * @param resource $handle
     * @param int $chunkSize
     *
     * @return string
     */
    protected function readFileChunk($handle, $chunkSize)
    {
        $byteCount = 0;
        $giantChunk = '';
        while (! feof($handle)) {
            // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
            // An example of a read buffered file is when reading from a URL
            $chunk = fread($handle, 8192);
            $byteCount += strlen($chunk);
            $giantChunk .= $chunk;
            if ($byteCount >= $chunkSize) {
                return $giantChunk;
            }
        }
        return $giantChunk;
    }

    /**
     * Return bytes from php.ini value
     *
     * @param string $iniName
     * @param string $val
     * @return number
     */
    protected function getIniBytes($iniName = '', $val = '')
    {
        if ($iniName !== '') {
            $val = ini_get($iniName);
            if ($val === false) {
                return 0;
            }
        }
        $val = trim($val, "bB \t\n\r\0\x0B");
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int)$val;
        switch ($last) {
            case 't':
                $val *= 1024;
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }

    /**
     * Return the number of memory bytes allocated to PHP
     *
     * @return int
     */
    protected function getMemoryUsedBytes()
    {
        return memory_get_usage(true);
    }

    /**
     * Get the size of a file resource
     *
     * @param $resource
     *
     * @return int
     */
    protected function getFileSizeBytes($resource)
    {
        return fstat($resource)['size'];
    }

    /**
     * Get a MediaFileUpload
     *
     * @param $client
     * @param $request
     * @param $mime
     * @param $chunkSizeBytes
     *
     * @return Google_Http_MediaFileUpload
     */
    protected function getMediaFileUpload($client, $request, $mime, $chunkSizeBytes)
    {
        return new Google_Http_MediaFileUpload($client, $request, $mime, null, true, $chunkSizeBytes);
    }

    /**
     * Apply optional parameters for each command
     *
     * @param   array   $params   The parameters
     * @param   string  $cmdName  The command name
     *
     * @return array
     *
     * @see https://developers.google.com/drive/v3/reference/files
     * @see \Google_Service_Drive_Resource_Files
     */
    protected function applyDefaultParams($params, $cmdName)
    {
        if (isset($this->defaultParams[$cmdName]) && is_array($this->defaultParams[$cmdName])) {
            return array_replace($this->defaultParams[$cmdName], $params);
        } else {
            return $params;
        }
    }

    /**
     * Enables Team Drive support by changing default parameters
     *
     * @return void
     *
     * @see https://developers.google.com/drive/v3/reference/files
     * @see \Google_Service_Drive_Resource_Files
     */
    public function enableTeamDriveSupport()
    {
        $this->defaultParams = array_merge_recursive(
            array_fill_keys([
                'files.copy', 'files.create', 'files.delete',
                'files.trash', 'files.get', 'files.list', 'files.update',
                'files.watch'
            ], ['supportsTeamDrives' => true]),
            $this->defaultParams
        );
    }

    /**
     * Selects Team Drive to operate by changing default parameters
     *
     * @return void
     *
     * @param   string   $teamDriveId   Team Drive id
     * @param   string   $corpora       Corpora value for files.list
     *
     * @see https://developers.google.com/drive/v3/reference/files
     * @see https://developers.google.com/drive/v3/reference/files/list
     * @see \Google_Service_Drive_Resource_Files
     */
    public function setTeamDriveId($teamDriveId, $corpora = 'teamDrive')
    {
        $this->enableTeamDriveSupport();
        $this->defaultParams = array_merge_recursive($this->defaultParams, [
            'files.list' => [
                'corpora' => $corpora,
                'includeTeamDriveItems' => true,
                'teamDriveId' => $teamDriveId
            ]
        ]);

        if ($this->root === 'root') {
            $this->setPathPrefix($teamDriveId);
            $this->root = $teamDriveId;
        }
    }
}
