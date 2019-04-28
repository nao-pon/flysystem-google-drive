<?php

use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter as Adapter;
use League\Flysystem\Config;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class StreamMock
{
    public function stream_open()
    {
        return true;
    }
}

class GoogleDriveTests extends PHPUnit_Framework_TestCase
{
    // TODO: implement all unit tests

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|Google_Service_Drive
     */
    protected $googleDriveService;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|Google_Client
     */
    protected $defaultGoogleClient;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|Google_Http_Batch
     */
    protected $defaultGoogleBatch;

    public function setUp()
    {
        parent::setUp();
        $this->defaultGoogleClient = $this->getMockBuilder('Google_Client')
            ->setMethods(['execute'])
            ->getMock();
        $this->defaultGoogleBatch = $this->getMockBuilder('Google_Http_Batch')
            ->setMethods(['execute'])
            ->setConstructorArgs([$this->defaultGoogleClient])
            ->getMock();
        $this->googleDriveService = $this->getMockBuilder('Google_Service_Drive')
            ->setMethods(['createBatch'])
            ->setConstructorArgs([$this->defaultGoogleClient])
            ->getMock();
        $this->googleDriveService->files = $this->getMockBuilder('Google_Service_Drive_Resource_Files')->disableOriginalConstructor()->getMock();
        $this->googleDriveService->expects($this->once())->method('createBatch')->willReturn($this->defaultGoogleBatch);
        $this->adapter = new Adapter($this->googleDriveService);
    }
    /**
     * @param int $iniBytes
     * @param int $usedBytes
     * @param int $fileSizeBytes
     * @param int $expectedChunkSizeBytes
     * @dataProvider chunkSizeCalculationDataProvider
     */
    public function testChunkSizeCalculation($iniBytes, $usedBytes, $fileSizeBytes, $expectedChunkSizeBytes)
    {
        $mediaFileUploadMock = $this->getMockBuilder('Google_Http_MediaFileUpload')->disableOriginalConstructor()->getMock();

        /** @var PHPUnit_Framework_MockObject_MockObject|Adapter $mockedAdapter */
        $mockedAdapter = $this->getMockBuilder(get_class($this->adapter))
            ->setConstructorArgs([$this->googleDriveService])
            ->setMethods(['getIniBytes', 'getMemoryUsedBytes', 'getFileSizeBytes', 'getMediaFileUpload'])
            ->getMock();
        $mockedAdapter->expects($this->once())
            ->method('getMediaFileUpload')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->equalTo($expectedChunkSizeBytes))
            ->willReturn($mediaFileUploadMock);
        $mockedAdapter->expects($this->once())->method('getIniBytes')->with('memory_limit')->willReturn($iniBytes);
        $mockedAdapter->expects($this->once())->method('getMemoryUsedBytes')->willReturn($usedBytes);
        $mockedAdapter->expects($this->once())->method('getFileSizeBytes')->willReturn($fileSizeBytes);

        $this->defaultGoogleBatch->method('execute')->willReturn([]);

        $this->defaultGoogleClient->method('execute')->willReturn(new Response(200, ['location' => 'example.com']));

        $this->googleDriveService->files->method('get')->willReturn(new Request('GET', 'example.com'));
        $this->googleDriveService->files->method('create')->willReturn(new Request('GET', 'example.com'));

        $mockedAdapter->write('/some/path', fopen('php://temp', 'w+'), new Config());
    }

    public function chunkSizeCalculationDataProvider()
    {
        $oneKiloByteOfBytes = 1024;
        $oneMegaByteOfBytes = $oneKiloByteOfBytes * 1024;
        $oneGigaByteOfBytes = $oneMegaByteOfBytes * 1024;
        return [
            'Test chunk size is reduced to 100MB when it would be over 100MB' =>
                [2 * $oneGigaByteOfBytes, $oneGigaByteOfBytes, 300 * $oneMegaByteOfBytes, 100 * $oneMegaByteOfBytes],
            'Test chunk size is at least 256KB when it would be under 256KB' =>
                [$oneMegaByteOfBytes, 500 * $oneKiloByteOfBytes, 500 * $oneKiloByteOfBytes, 256 * $oneKiloByteOfBytes],
            'Test chunk size is correctly calculated when no rounding to nearest 256KB required' =>
                [1000 * $oneMegaByteOfBytes, 800 * $oneMegaByteOfBytes, 75 * $oneMegaByteOfBytes, 50 * $oneMegaByteOfBytes],
            'Test chunk size is correctly calculated when almost to next multiple of 256KB rounds down' =>
                [1000 * $oneMegaByteOfBytes, 800 * $oneMegaByteOfBytes, (75 * $oneMegaByteOfBytes) + (255 * $oneKiloByteOfBytes), (50 * $oneMegaByteOfBytes)],
            'Test chunk size is correctly calculated when just over multiple of 256KB rounds down' =>
                [1000 * $oneMegaByteOfBytes, 800 * $oneMegaByteOfBytes, (75 * $oneMegaByteOfBytes) + (1 * $oneKiloByteOfBytes), (50 * $oneMegaByteOfBytes)],
        ];
    }
}