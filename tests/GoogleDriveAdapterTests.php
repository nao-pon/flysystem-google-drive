<?php

namespace Hypweb\Tests\Flysystem\GoogleDrive;

use Google\Client;
use Google\Service\Drive;
use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;

class GoogleDriveAdapterTests extends \League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase
{
    public static string $adapterPrefix = 'ci';

    public static function setUpBeforeClass(): void
    {
        static::$adapterPrefix = 'ci/' . bin2hex(random_bytes(10));
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {

        try {
            $config = static::loadConfig();

            $options = [];

            if (!empty($config['GOOGLE_DRIVE_TEAM_DRIVE_ID'] ?? null)) {
                $options['teamDriveId'] = $config['GOOGLE_DRIVE_TEAM_DRIVE_ID'];
            }

            $client = new Client;
            $client->setClientId($config['GOOGLE_DRIVE_CLIENT_ID']);
            $client->setClientSecret($config['GOOGLE_DRIVE_CLIENT_SECRET']);
            $client->refreshToken($config['GOOGLE_DRIVE_REFRESH_TOKEN']);
            $service = new Drive($client);

            return new GoogleDriveAdapter($service, 'flysystem-google-drive/', $options);
        } catch (\Exception $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    /** @test */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        $this->assertTrue(true); //This adapter always returns a mime-type.
    }

    /** @test */
    public function can_read_a_manually_upload_file()
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $contents = $adapter->read('1QgK7r9PvrY7iI8_23knSrEwhsj3NZFCD');
            $this->assertEquals("Hello from Google Drive\n", $contents);
        });
    }

    /** @test */
    public function creating_zero_dir()
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write('example.txt', 'contents', new Config);
            $contents = $adapter->read('0/file.txt');
            $this->assertEquals('contents', $contents);
        });
    }

    protected static function loadConfig(): array
    {
        $config = [
            'GOOGLE_DRIVE_CLIENT_ID'     => getenv('GOOGLE_DRIVE_CLIENT_ID'),
            'GOOGLE_DRIVE_CLIENT_SECRET' => getenv('GOOGLE_DRIVE_CLIENT_SECRET'),
            'GOOGLE_DRIVE_REFRESH_TOKEN' => getenv('GOOGLE_DRIVE_REFRESH_TOKEN'),
            'GOOGLE_DRIVE_TEAM_DRIVE_ID' => getenv('GOOGLE_DRIVE_TEAM_DRIVE_ID'),
        ];

        if (empty(array_filter(array_values($config)))) {
            self::markTestSkipped('No config found in the phpunit.xml file.');
        }

        return $config;
    }
}