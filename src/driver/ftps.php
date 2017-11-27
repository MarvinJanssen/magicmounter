<?php

namespace MagicMounter\driver;

use MagicMounter\Magic;
use MagicMounter\Exception;

/**
 * MagicMounter, by Marvin Janssen (http://marvinjanssen.me), released in 2017.
 *
 * The FTPS magic driver provides a transparent FTPS transport. It extends \MagicMounter\driver\Ftp
 */
class Ftps extends \MagicMounter\driver\Ftp
	{
	public function __construct(array $options)
		{
		if (!extension_loaded('openssl') || !function_exists('ftp_ssl_connect'))
			throw new Exception('OpenSSL extension not available',103);
		parent::__construct($options);
		}

	protected function ftp_connect($host,$port,$timeout)
		{
		return ftp_ssl_connect($host,$port,$timeout);
		}
	}