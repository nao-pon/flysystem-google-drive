<?php
namespace Hypweb\Flysystem\GoogleDrive;

use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Service_Drive_ChildList;
use Google_Service_Drive_ParentReference;
use Google_Service_Drive_Permission;
use Google_Http_MediaFileUpload;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class GoogleDriveAdapter extends AbstractAdapter
{

    /**
     * Fetch fields setting
     *
     * @var string
     */
    const FETCHFIELDS = 'items(copyable,downloadUrl,editable,exportLinks,fileSize,id,mimeType,modifiedDate,parents/id,permissions(domain,emailAddress,id,kind,name,role,type,value,withLink),selfLink,shareable,shared,title,webContentLink),nextPageToken';

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
        'publishPermission' => [
            'type' => 'anyone',
            'role' => 'reader',
            'withLink' => true
        ]
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

    public function __construct(Google_Service_Drive $service, $root = null, $options = [])
    {
        if (! $root) {
            $root = 'root';
        }
        $this->service = $service;
        $this->setPathPrefix($root);
        $this->root = $root;
        
        $options = array_replace_recursive(static::$defaultOptions, $options);
        
        $this->spaces = $options['spaces'];
        $this->useHasDir = $options['useHasDir'];
        $this->publishPermission = $options['publishPermission'];
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
        list ($newParent, $newTitle) = $this->splitPath($newpath);
        
        $file = $this->service->files->get($fileId);
        $file->setTitle($newTitle);
        $opts = [];
        if ($newParent !== $oldParent) {
            $opts['addParents'] = $newParent;
            $opts['removeParents'] = $oldParent;
        }
        
        $updatedFile = $this->service->files->update($fileId, $file, $opts);
        
        if ($updatedFile) {
            $this->cacheFileObjects[$updatedFile->getId()] = $updatedFile;
            $this->cacheFileObjects[$newTitle] = $updatedFile;
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
        
        $parent = new Google_Service_Drive_ParentReference();
        $parent->setId($newParentId);
        
        $file = new Google_Service_Drive_DriveFile();
        $file->setTitle($fileName);
        $file->setParents([
            $parent
        ]);
        
        $newFile = $this->service->files->copy($srcId, $file);
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
        list ($parentId, $id) = $this->splitPath($path);
        $result = true;
        $file = $this->getFileObject($id);
        if ($parents = $file->getParents()) {
            if (count($parents) > 1) {
                $newParents = [];
                foreach ($parents as $parent) {
                    if ($parent['id'] !== $parentId) {
                        $newParents[] = $parent;
                    }
                }
                $file->setParents($newParents);
                if ($this->service->files->patch($id, $file, [
                    'fields' => 'parents'
                ])) {
                    unset($this->cacheFileObjects[$id], $this->cacheHasDirs[$id]);
                    return true;
                }
            } else {
                if ($this->service->files->trash($id)) {
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
            $path_parts = pathinfo($name);
            $result = [
                'path' => $dirname . '/' . $itemId,
                'filename' => $path_parts['filename'],
                'extension' => isset($path_parts['extension'])? $path_parts['extension'] : ''
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
        return ($this->getFileObject($path) instanceof Google_Service_Drive_DriveFile);
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
        $arr = $this->readStream($path);
        if ($arr && isset($arr['stream'])) {
            return [
                'contents' => stream_get_contents($arr['stream'])
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
        if ($file = $this->getFileObject($path)) {
            if ($dlurl = $this->getDownloadUrl($file)) {
                $url = parse_url($dlurl);
                $client = $this->service->getClient();
                $token = $client->getAccessToken();
                $access_token = '';
                if (is_array($token)) {
                    $access_token = $token['access_token'];
                } else {
                    if ($token = @json_decode($client->getAccessToken())) {
                        $access_token = $token->access_token;
                    }
                }
                if ($access_token) {
                    $stream = stream_socket_client('ssl://' . $url['host'] . ':443');
                    fputs($stream, "GET {$url['path']}?{$url['query']} HTTP/1.1\r\n");
                    fputs($stream, "Host: {$url['host']}\r\n");
                    fputs($stream, "Authorization: Bearer {$access_token}\r\n");
                    fputs($stream, "Connection: Close\r\n");
                    fputs($stream, "\r\n");
                    while (trim(fgets($stream)) !== '') {}
                    ;
                    return compact('stream');
                }
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
        $dirname = $this->applyPathPrefix($dirname);
        return array_values($this->getItems($dirname, $recursive));
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
        $path = $this->applyPathPrefix($path);
        if ($obj = $this->getFileObject($path)) {
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
            'visibility' => $this->getRawVisibility($this->applyPathPrefix($path))
        ];
    }

    /**
     * Apply the path prefix.
     *
     * @param string $path            
     *
     * @return string prefixed path
     */
    public function applyPathPrefix($path)
    {
        return $path;
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
            return $this->getDownloadUrl($this->getFileObject($path), true);
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
        $opts = [
            'maxResults' => 1,
            'q' => sprintf('trashed = false and mimeType = "%s"', self::DIRMIME)
        ];
        $paths = [];
        $client->setUseBatch(true);
        $batch = $service->createBatch();
        $i = 0;
        foreach ($targets as $id) {
            $request = $service->children->listChildren($id, $opts);
            $key = ++ $i;
            $batch->add($request, (string) $key);
            $paths['response-' . $key] = $id;
        }
        $results = $batch->execute();
        foreach ($results as $key => $result) {
            if ($result instanceof Google_Service_Drive_ChildList) {
                $object[$paths[$key]]['hasdir'] = $this->cacheHasDirs[$paths[$key]] = (bool) $result->getItems();
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
                if ($this->service->permissions->insert($file->getId(), $permission)) {
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
                $dirName = $paths? array_pop($paths) : '';
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
        $path_parts = pathinfo($object->getTitle());
        $result = [];
        $result['type'] = $object->mimeType === self::DIRMIME ? 'dir' : 'file';
        $result['path'] = ($dirname ? ($dirname . '/') : '') . $id;
        $result['filename'] = $path_parts['filename'];
        $result['extension'] = isset($path_parts['extension'])? $path_parts['extension'] : '';
        $result['timestamp'] = strtotime($object->getModifiedDate());
        if ($result['type'] === 'file') {
            $result['mimetype'] = $object->mimeType;
            $result['size'] = (int) $object->getFileSize();
        }
        if ($result['type'] === 'dir') {
            $result['size'] = 0;
            if ($this->useHasDir) {
                $result['hasdir'] = isset($this->cacheHasDirs[$id])? $this->cacheHasDirs[$id] : false;
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
            'maxResults' => $maxResults ?  : 1000,
            'fields' => self::FETCHFIELDS,
            'spaces' => $this->spaces,
            'q' => sprintf('trashed = false and "%s" in parents', $itemId)
        ];
        if ($query) {
            $parameters['q'] .= ' and (' . $query . ')';
            ;
        }
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
                                $results = array_merge($results, $this->getItems($pathName, true));
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
     *            
     * @return Google_Service_Drive_DriveFile|null
     */
    protected function getFileObject($path, $useCache = true)
    {
        list (, $itemId) = $this->splitPath($path);
        if ($useCache && isset($this->cacheFileObjects[$itemId])) {
            return $this->cacheFileObjects[$itemId];
        }
        
        $service = $this->service;
        $client = $service->getClient();
        
        $client->setUseBatch(true);
        $batch = $service->createBatch();
        
        $q = 'trashed = false';
        
        $batch->add($this->service->files->get($itemId, []), 'obj');
        if ($this->useHasDir) {
            $batch->add($service->children->listChildren($itemId, [
                'maxResults' => 1,
                'q' => sprintf('trashed = false and mimeType = "%s"', self::DIRMIME)
            ]), 'hasdir');
        }
        $results = array_values($batch->execute());
        
        list ($fileObj, $hasdir) = array_pad($results, 2, null);
        $client->setUseBatch(false);
        
        if ($fileObj instanceof Google_Service_Drive_DriveFile) {
            if ($hasdir && $fileObj->mimeType === self::DIRMIME) {
                if ($hasdir instanceof Google_Service_Drive_ChildList) {
                    $this->cacheHasDirs[$fileObj->getId()] = (bool) $hasdir->getItems();
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
    protected function getDownloadUrl($file, $parmanent = false)
    {
        try {
            if (strpos($file->mimeType, 'application/vnd.google-apps') !== 0) {
                if ($parmanent) {
                    if ($url = $file->getWebContentLink()) {
                        return str_replace('&export=download', '', $url);
                    }
                } else {
                    if ($url = $file->getSelfLink()) {
                        return $url . '?alt=media';
                    }
                }
            } else {
                if (($links = $file->getExportLinks()) && count($links) > 0) {
                    $links = array_values($links);
                    return $links[0];
                }
            }
        } catch (Exception $e) {
            return false;
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
        $parent = new Google_Service_Drive_ParentReference();
        $parent->setId($parentId);
        
        $file = new Google_Service_Drive_DriveFile();
        $file->setTitle($name);
        $file->setParents([
            $parent
        ]);
        $file->setMimeType(self::DIRMIME);
        
        return $this->service->files->insert($file);
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
        
        $parent = new Google_Service_Drive_ParentReference();
        $parent->setId($parentId);
        
        if (! $file = $this->getFileObject($path)) {
            $file = new Google_Service_Drive_DriveFile();
            $mode = 'insert';
        }
        if ($mode === 'insert') {
            $file->setTitle($fileName);
            $file->setParents([
                $parent
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
        
        if (! $mime) {
            $mime = Util::guessMimeType($fileName, $isResource ? '' : $contents);
        }
        $file->setMimeType($mime);
        
        if ($isResource) {
            $chunkSizeBytes = 1 * 1024 * 1024;
            $client = $this->service->getClient();
            // Call the API with the media upload, defer so it doesn't immediately return.
            $client->setDefer(true);
            if ($mode === 'insert') {
                $request = $this->service->files->insert($file);
            } else {
                $request = $this->service->files->update($file->getId(), $file);
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
        } else {
            $params = [
                'data' => $contents,
                'uploadType' => 'media'
            ];
            if ($mode === 'insert') {
                $obj = $this->service->files->insert($file, $params);
            } else {
                $obj = $this->service->files->update($file->getId(), $file, $params);
            }
        }
        
        if ($obj instanceof Google_Service_Drive_DriveFile) {
            $this->cacheFileObjects[$obj->getId()] = $obj;
            if ($mode === 'insert') {
                $this->cacheFileObjects[$fileName] = $obj;
            }
            $result = $this->normaliseObject($obj, $parentId);
            
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
}
