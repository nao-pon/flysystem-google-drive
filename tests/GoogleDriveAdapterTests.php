<?php

use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter as Adapter;
use League\Flysystem\Config;

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

	public function testTrueIsTrue()
	{
		$this->assertTrue(true);
	}
}