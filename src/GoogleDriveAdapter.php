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
        'corpora' => 'teamDrive'
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
            $this->cacheFileObjects[$newName] = $updatedFile;
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
            $this->cacheFileObjects[$fileName] = $newFile;
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
            list ($parentId, $id) = $this->splitPath($path);
            if ($parents = $file->getParents()) {
                $file = new Google_Service_Drive_DriveFile();
                $opts = [];
                if (count($parents) > 1) {
                    $opts['removeParents'] = $parentId;
                } else {
                    $file->setTrashed(true);
                }
                if ($this->service->files->update($id, $file, $this->applyDefaultParams($opts, 'files.update'))) {
                    unset($this->cacheFileObjects[$id], $this->cacheHasDirs[$id]);
                    return true;
                }
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
            $this->cacheFileObjects[$name] = $folder; // for confirmation by getMetaData() oe has() while in this connection
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
     * Do cache cacheHasDirs with batch request
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
        $client->setUseBatch(true);
        $batch = $service->createBatch();
        $i = 0;
        foreach ($targets as $id) {
            $opts['q'] = sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $id, self::DIRMIME);
            $request = $gFiles->listFiles($this->applyDefaultParams($opts, 'files.list'));
            $key = ++ $i;
            $batch->add($request, (string) $key);
            $paths['response-' . $key] = $id;
        }
        $results = $batch->execute();
        foreach ($results as $key => $result) {
            if ($result instanceof Google_Service_Drive_FileList) {
                $object[$paths[$key]]['hasdir'] = $this->cacheHasDirs[$paths[$key]] = (bool) $result->getFiles();
            }
        }
        $client->setUseBatch(false);
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
        if ($path === '' || $path === '/') {
            $fileName = $this->root;
            $dirName = '';
        } else {
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
            $fileName
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
        list (, $itemId) = $this->splitPath($path);
        if (isset($this->cacheFileObjects[$itemId])) {
            return $this->cacheFileObjects[$itemId];
        }

        $service = $this->service;
        $client = $service->getClient();

        $client->setUseBatch(true);
        $batch = $service->createBatch();

        $opts = [
            'fields' => $this->fetchfieldsGet
        ];

        $batch->add($this->service->files->get($itemId, $this->applyDefaultParams($opts, 'files.get')), 'obj');
        if ($checkDir && $this->useHasDir) {
            $batch->add($service->files->listFiles($this->applyDefaultParams([
                'pageSize' => 1,
                'q' => sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $itemId, self::DIRMIME)
            ], 'files.list')), 'hasdir');
        }
        $results = array_values($batch->execute());

        list ($fileObj, $hasdir) = array_pad($results, 2, null);
        $client->setUseBatch(false);

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
        $mode = 'update';
        $mime = $config->get('mimetype');

        $srcFile = $this->getFileObject($path);
        $file = new Google_Service_Drive_DriveFile();
        if (! $srcFile) {
            $mode = 'insert';
            $file->setName($fileName);
            $file->setParents([
                $parentId
            ]);
        }

        $isResource = false;
        if (is_resource($contents)) {
            $fstat = @fstat($contents);
            if (! empty($fstat['size'])) {
                $isResource = true;
            }
            if (! $isResource) {
                $contents = stream_get_contents($contents);
            }
        }

        if ($isResource) {
            // set chunk size (max: 100MB)
            $chunkSizeBytes = 100 * 1024 * 1024;
            $memory = $this->getIniBytes('memory_limit');
            if ($memory > 0) {
                $chunkSizeBytes = max(262144 , min([
                    $chunkSizeBytes,
                    (intval($memory / 4 / 256) * 256)
                ]));
            }
            if ($fstat['size'] < $chunkSizeBytes) {
                $isResource = false;
                $contents = stream_get_contents($contents);
            }
        }

        if (! $mime) {
            $mime = Util::guessMimeType($fileName, $isResource ? '' : $contents);
        }
        $file->setMimeType($mime);

        if ($isResource) {
            $client = $this->service->getClient();
            // Call the API with the media upload, defer so it doesn't immediately return.
            $client->setDefer(true);
            if ($mode === 'insert') {
                $request = $this->service->files->create($file, $this->applyDefaultParams([
                    'fields' => $this->fetchfieldsGet
                ], 'files.create'));
            } else {
                $request = $this->service->files->update($srcFile->getId(), $file, $this->applyDefaultParams([
                    'fields' => $this->fetchfieldsGet
                ], 'files.update'));
            }

            // Create a media file upload to represent our upload process.
            $media = new Google_Http_MediaFileUpload($client, $request, $mime, null, true, $chunkSizeBytes);
            $media->setFileSize($fstat['size']);
            // Upload the various chunks. $status will be false until the process is
            // complete.
            $status = false;
            $handle = $contents;
            while (! $status && ! feof($handle)) {
                // read until you get $chunkSizeBytes from TESTFILE
                // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
                // An example of a read buffered file is when reading from a URL
                $chunk = $this->readFileChunk($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }
            // The final value of $status will be the data from the API for the object
            // that has been uploaded.
            if ($status != false) {
                $obj = $status;
            }

            $client->setDefer(false);
        } else {
            $params = [
                'data' => $contents,
                'uploadType' => 'media',
                'fields' => $this->fetchfieldsGet
            ];
            if ($mode === 'insert') {
                $obj = $this->service->files->create($file, $this->applyDefaultParams($params, 'files.create'));
            } else {
                $obj = $this->service->files->update($srcFile->getId(), $file, $this->applyDefaultParams($params, 'files.update'));
            }
        }

        if ($obj instanceof Google_Service_Drive_DriveFile) {
            $this->cacheFileObjects[$obj->getId()] = $obj;
            if ($mode === 'insert') {
                $this->cacheFileObjects[$fileName] = $obj;
            }
            $result = $this->normaliseObject($obj, Util::dirname($path));

            if ($visibility = $config->get('visibility')) {
                if ($this->setVisibility($path, $visibility)) {
                    $result['visibility'] = $visibility;
                }
            }

            return $result;
        }

        return false;
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

        $this->setPathPrefix($teamDriveId);
        $this->root = $teamDriveId;
    }
}
