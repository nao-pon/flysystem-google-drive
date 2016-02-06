<?php
namespace Hypweb\Flysystem\GoogleDrive;

use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Http_Request;
use Google_Http_MediaFileUpload;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use League\Flysystem\Util;

class GoogleDriveAdapter extends AbstractAdapter
{

    protected $service;

    private $cacheFileObjects = [];

    public function __construct(Google_Service_Drive $service, $prefix = null)
    {
        $this->service = $service;
        $this->setPathPrefix($prefix);
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
        $path = $this->applyPathPrefix($path);
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
        $path = $this->applyPathPrefix($path);
        return $this->upload($path, $resource, $config);
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
        return $this->writeStream($path, $resource, $config);
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
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);
        list ($newDirName, $newTitle) = $this->splitPath($newpath);
        $parentId = $this->ensureDirectory($newDirName);

        if ($fileId = $this->getFileId($path)) {
            $file = new Google_Service_Drive_DriveFile();
            $file->setTitle($newTitle);
            $file->setParents([
                [
                    'kind' => 'drive#fileLink',
                    'id' => $parentId
                ]
            ]);

            $updatedFile = $this->service->files->patch($fileId, $file, [
                'fields' => 'title,parents'
            ]);

            if ($updatedFile) {
                $this->cacheFileObjects[$path] = $updatedFile;
                return true;
            }
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
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        $res = false;

        if ($srcId = $this->getFileId($path)) {
            list ($newDirName, $fileName) = $this->splitPath($newpath);

            $newParentId = $this->ensureDirectory($newDirName);

            $file = new Google_Service_Drive_DriveFile();
            $file->setTitle($fileName);
            $file->setParents([
                [
                    'kind' => 'drive#fileLink',
                    'id' => $newParentId
                ]
            ]);

            $res = ! is_null($this->service->files->copy($srcId, $file));
        }
        return $res;
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
        $path = $this->applyPathPrefix($path);
        if ($id = $this->getFileId($path)) {
            if ($result = is_null($this->service->files->delete($id))) {
                unset($this->cacheFileObjects[$path]);
            }
            return $result;
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
        $dirname = $this->applyPathPrefix($dirname);
        if ($folderId = $this->getFileId($dirname)) {
            if ($result = is_null($this->service->files->delete($folderId))) {
                unset($this->cacheFileObjects[$dirname]);
            }
            return $result;
        }
        return false;
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
        $dirname = $this->applyPathPrefix($dirname);
        $folderId = $this->getFileId($dirname);

        if ($folderId === false) {
            if ($this->ensureDirectory($dirname)) {
                return [
                    'path' => $dirname
                ];
            }
        }
        return false;
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
        // Todo
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
        $path = $this->applyPathPrefix($path);
        return ($this->getFileId($path) !== false);
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
        $path = $this->applyPathPrefix($path);
        $file = $this->getFileObject($path);

        if (false !== ($contents = $this->getFileContents($file))) {
            return [
                'contents' => $contents
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
        $path = $this->applyPathPrefix($path);
        $fileId = $this->getFileId($path);
        if ($fileId && $file = $this->service->files->get($fileId)) {
            if ($dlurl = $this->getDownloadUrl($file)) {
                $url = parse_url($dlurl);
                $client = $this->service->getClient();
                if ($token = @json_decode($client->getAccessToken())) {
                    $stream = stream_socket_client('ssl://' . $url['host'] . ':443');
                    fputs($stream, "GET {$url['path']}?{$url['query']}&oauth_token=" . $token->access_token . " HTTP/1.1\r\n");
                    fputs($stream, "Host: {$url['host']}\r\n");
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
        if (! $parentId = $this->getFileId($dirname)) {
            return [];
        }

        $q = sprintf('trashed = false and "%s" in parents', $parentId);
        $fileObjs = $this->service->files->listFiles([
            'q' => $q
        ]);

        $results = [];
        if (is_a($fileObjs, 'Google_Service_Drive_FileList')) {
            foreach ($fileObjs as $obj) {
                $childName = $dirname . '/' . $obj->getTitle();
                $this->cacheFileObjects[$childName] = $obj;
                $result = $this->normaliseObject($obj, $dirname);
                $results[] = $result;
                if ($recursive && $result['type'] === 'dir') {
                    $results = array_merge($results, $this->listContents($this->removePathPrefix($childName), true));
                }
            }
        }
        return $results;
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
            return $this->normaliseObject($obj, Util::dirname($path));
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
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        // Todo
        return false;
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
        $prefixed = parent::applyPathPrefix($path);
        return '/' . trim($prefixed, '/');
    }

    protected function splitPath($path)
    {
        $paths = explode('/', $path);
        $fileName = array_pop($paths);
        $dirName = join('/', $paths);
        return [
            $dirName,
            $fileName
        ];
    }

    protected function normaliseObject($object, $dir)
    {
        $result = [];
        $result['type'] = $object->mimeType === 'application/vnd.google-apps.folder' ? 'dir' : 'file';
        $result['path'] = trim($this->removePathPrefix(rtrim($dir, '/') . '/' . $object->getTitle()), '/');
        $result['timestamp'] = strtotime($object->getModifiedDate());
        if ($result['type'] === 'file') {
            $result['mimetype'] = $object->mimeType;
            $result['size'] = $object->getFileSize();
        }
        return $result;
    }

    protected function ensureDirectory($path)
    {
        $dirId = $this->getFileId($path);
        if (! $dirId) {
            list ($dirName, $fileName) = $this->splitPath($path);
            $pdirId = $this->ensureDirectory($dirName);
            $folder = $this->createDirectory($fileName, $pdirId);
            $dirId = $folder->id;
        }
        return $dirId;
    }

    protected function getFileObject($path)
    {
        if (isset($this->cacheFileObjects[$path])) {
            return $this->cacheFileObjects[$path];
        }

        list ($dirName, $fileName) = $this->splitPath($path);

        $parentId = null;
        if ($dirName === '' || $dirName === '.' || $dirName === '/') {
            $parentId = null;
        } else
            if (! empty($dirName)) {
                $parentId = $this->getFileId($dirName);
            }

        if (is_null($parentId)) {
            $parentId = 'root';
        }

        $q = 'title = "' . $fileName . '" and trashed = false';
        $q .= sprintf(' and "%s" in parents', $parentId);

        $obj = $this->service->files->listFiles([
            'q' => $q
        ]);
        $files = [];
        if (is_a($obj, 'Google_Service_Drive_FileList')) {
            $files = $obj->getItems();
        }

        $fileObj = null;
        if (count($files) > 0) {
            $fileObj = $files[0];
        }
        $this->cacheFileObjects[$path] = $fileObj;

        return $fileObj;
    }

    protected function getFileId($path)
    {
        if ($fileObj = $this->getFileObject($path)) {
            return $fileObj->id;
        }
        return false;
    }

    protected function getFileContents($file)
    {
        $downloadUrl = $file->getDownloadUrl();

        if ($downloadUrl) {
            $request = new Google_Http_Request($downloadUrl, 'GET', null, null);
            $httpRequest = $this->service->getClient()
                ->getAuth()
                ->authenticatedRequest($request);
            if ($httpRequest->getResponseHttpCode() == 200) {
                return (string) $httpRequest->getResponseBody();
            }
        }
        return false;
    }

    protected function getDownloadUrl($file)
    {
        if ($url = $file->getDownloadUrl()) {
            return $url;
        } else
            if (($links = $file->getExportLinks()) && count($links) > 0) {
                $links = array_values($links);
                return $links[0];
            }
        return false;
    }

    protected function createDirectory($name, $parentId = null)
    {
        $file = new Google_Service_Drive_DriveFile();
        $file->setTitle($name);
        $file->setParents([
            [
                'id' => $parentId
            ]
        ]);
        $file->setMimeType('application/vnd.google-apps.folder');

        $obj = $this->service->files->insert($file);
        if (is_a($obj, 'Google_Service_Drive_DriveFile')) {
            $this->cacheFileObjects[$name] = $obj;
        }
        return $obj;
    }

    protected function upload($path, $contents, Config $config)
    {
        list ($dirName, $fileName) = $this->splitPath($path);
        $mode = 'update';
        $mime = $config->get('mimetype');

        $parentId = $this->ensureDirectory($dirName);

        if (! $file = $this->getFileObject($path)) {
            $file = new Google_Service_Drive_DriveFile();
            $mode = 'insert';
        }
        $file->setTitle($fileName);
        $file->setParents([
            [
                'kind' => 'drive#fileLink',
                'id' => $parentId
            ]
        ]);

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
            $mime = Util::guessMimeType($path, $isResource ? '' : $contents);
        }
        $file->setMimeType($mime);

        if ($isResource) {
            $fstat = fstat($contents);
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

        if (is_a($obj, 'Google_Service_Drive_DriveFile')) {
            $this->cacheFileObjects[$path] = $obj;
            return $this->normaliseObject($obj, Util::dirname($path));
        }
        return false;
    }

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
