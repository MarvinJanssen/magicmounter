<?php

namespace MagicMounter\driver;

use MagicMounter\Magic;
use MagicMounter\Exception;

/**
 * MagicMounter, by Marvin Janssen (http://marvinjanssen.me), released in 2017.
 *
 * The FTP magic driver provides a transparent FTP transport. You can work with files as if they
 * were on the local system. The driver implements as many file system features as the PHP FTP
 * extension permits.
 */
class Ftp implements \MagicMounter\Driver
	{
	protected $control_connection;
	protected $resources = [];
	protected $directories = [];

	protected $options;

	protected $last_measurements;

	public function __construct(array $options)
		{
		if (!function_exists('ftp_connect'))
			throw new Exception('FTP extension not available',103);
		if (!isset($options['host']))
			throw new Exception("The 'host' option is required.",200);
		$options['username'] = isset($options['username']) ? $options['username'] : 'anonymous';
		$options['password'] = isset($options['password']) ? $options['password'] : '';
		$options['port'] = isset($options['port']) ? $options['port'] : 21;
		$options['directory'] = isset($options['directory']) ? str_replace("\x00",'',$options['directory']) : '';
		$options['timeout'] = isset($options['timeout']) ? $options['timeout'] : 90;
		$options['measure_transfers'] = isset($options['measure_transfers']) ? $options['measure_transfers'] : false;
		$options['exception_on_read_error'] = isset($options['exception_on_read_error']) ? $options['exception_on_read_error'] : true;
		if (!empty($options['directory']) && $options['directory'][0] !== '/')
			$options['directory'] = '/'.$options['directory'];
		$this->options = $options;
		}

	public function __destruct()
		{
		$this->unmount();
		}

	public function unmount()
		{
		foreach ($this->resources as $resource)
			{
			if (is_resource($resource['connection']))
				//{
				//@ftp_raw($resource['connection'],'ABOR');
				@ftp_close($resource['connection']);
				//}
			}
		if (is_resource($this->control_connection))
			@ftp_close($this->control_connection);
		$this->resources = [];
		$this->directories = [];
		return true;
		}

	public function quote(array $parameters,Magic $magic_stream = null)
		{
		if (isset($parameters[0]))
			{
			if ($magic_stream === null)
				{
				if (strpos($parameters[0],'last_') === 0)
					{
					$parameters[0] = substr($parameters[0],5);
					if (isset($this->last_measurements[$parameters[0]]))
						return $this->last_measurements[$parameters[0]];
					}
				}
			else
				{
				if (isset($this->resources[$magic_stream->id]['measurements'][$parameters[0]]))
					return $this->resources[$magic_stream->id]['measurements'][$parameters[0]];
				}
			}
		return null;
		}

	protected function measurements($id,$type,$bytes,$microtime)
		{
		if (isset($this->resources[$id]['measurements']) && ($type === 'download' || $type === 'upload'))
			{
			$this->resources[$id]['measurements']['bytes_'.$type.'ed'] += $bytes;
			$this->resources[$id]['measurements'][$type.'_transfer_time'] += $microtime;
			$this->resources[$id]['measurements'][$type.'_speed'] = $this->resources[$id]['measurements']['bytes_'.$type.'ed']/$this->resources[$id]['measurements'][$type.'_transfer_time'];
			$this->last_measurements = $this->resources[$id]['measurements'];
			}
		}

	protected function ftp_connect($host,$port,$timeout)
		{
		return ftp_connect($host,$port,$timeout);
		}

	protected function create_ftp_resource($id = null,$path = '')
		{
		if ($ftp = $this->ftp_connect($this->options['host'],$this->options['port'],$this->options['timeout']))
			{
			if (ftp_login($ftp,$this->options['username'],$this->options['password']))
				{
				if ($this->options['directory'] !== '')
					ftp_chdir($ftp,$this->options['directory']);
				ftp_pasv($ftp,true);
				ftp_set_option($ftp,FTP_AUTOSEEK,false);
				if ($id !== null)
					$this->resources[$id] = ['connection'=>$ftp,'read'=>false,'write'=>false,'path'=>str_replace("\x00",'',$path),'pointer'=>0];
				return $ftp;
				}
			else
				throw new Exception("Could not login to FTP server '".$this->options['host'].":".$this->options['port']."' using the provided credentials.",202);
			}
		throw new Exception("Could not connect to FTP server '".$this->options['host'].":".$this->options['port']."'.",202);
		}

	public function destroy_ftp_resource($id)
		{
		if (isset($this->resources[$id]))
			{
			if (is_resource($this->resources[$id]['connection']))
				@ftp_close($this->resources[$id]['connection']);
			if (isset($this->resources[$id]['read_buffer']))
				{
				if ($this->resources[$id]['read_buffer'][1] === FTP_MOREDATA)
					@ftp_raw($this->resources[$id]['connection'],'ABOR');
				@fclose($this->resources[$id]['read_buffer'][0]);
				}
			unset($this->resources[$id]);
			return true;
			}
		return false;
		}

	public function refresh_ftp_resource($id)
		{
		// FTP is a crappy protocol, which forces us to refresh the connection under some circumstances.
		if (isset($this->resources[$id]))
			{
			$resource = $this->resources[$id];
			unset($resource['read_buffer']);
			$this->destroy_ftp_resource($id);
			$this->create_ftp_resource($id,$resource['path']);
			$resource['connection'] = $this->resources[$id]['connection'];
			$this->resources[$id] = $resource;
			return true;
			}
		return false;
		}

	protected function control_connection()
		{
		if (!is_resource($this->control_connection))
			$this->control_connection = $this->create_ftp_resource();
		else
			{
			$result = @ftp_raw($this->control_connection,'NOOP'); //TODO- do we need this?
			if (empty($result))
				$this->control_connection = $this->create_ftp_resource();
			}
		return $this->control_connection;
		}

	public function stream_open(array $path_info,$mode,$options,&$opened_path,Magic $magic_stream)
		{
		$this->create_ftp_resource($magic_stream->id,(isset($path_info['path'])?$path_info['path']:''));
		if ($this->resources[$magic_stream->id])
			{
			// why even populate opened_path?
			$opened_path = 'ftp://'.urlencode($this->options['username']).(!$this->options['password']?':'.urlencode($this->options['password']):'').'@'.$this->options['host'].':'.$this->options['port'].$this->options['directory'].$this->resources[$magic_stream->id]['path'];

			$measure_transfer = $this->options['measure_transfers'];

			if (!$measure_transfer)
				{
				//TODO- in the future we may use $magic_stream->context to modify these streams when they are opened
				if ($magic_stream->context !== null && ($context = stream_context_get_options($magic_stream->context)))
					$measure_transfer = !empty($context['magic']['options']['measure_transfer']);
				}
			if ($measure_transfer)
				$this->resources[$magic_stream->id]['measurements']	= ['download_speed'=>0.0,'upload_speed'=>0.0,'bytes_downloaded'=>0,'bytes_uploaded'=>0,'download_transfer_time'=>0.0,'upload_transfer_time'=>0.0];
			switch ($mode)
				{
				case 'r+':
				case 'r+b':
				case 'r+t':
					$this->resources[$magic_stream->id]['write'] = true;
				case 'r':
				case 'rb':
				case 'rt':
					$this->resources[$magic_stream->id]['read'] = true;
					break;

				case 'w+':
				case 'w+b':
				case 'w+t':
					$this->resources[$magic_stream->id]['read'] = true;
				case 'w':
				case 'wb':
				case 'wt':
					$this->resources[$magic_stream->id]['write'] = true;
					$this->stream_truncate(0,$magic_stream);
					break;

				case 'a+':
				case 'a+b':
				case 'a+t':
					$this->resources[$magic_stream->id]['read'] = true;
				case 'a':
				case 'ab':
				case 'at':
					$this->resources[$magic_stream->id]['write'] = true;
					$this->stream_seek(0,SEEK_END,$magic_stream);
					$this->resources[$magic_stream->id]['append_pointer'] = $this->resources[$magic_stream->id]['pointer'];
					break;

				case 'x+':
				case 'x+b':
				case 'x+t':
					$this->resources[$magic_stream->id]['read'] = true;
				case 'x':
				case 'xb':
				case 'xt':
					$this->resources[$magic_stream->id]['write'] = true;
					$stat = $this->stream_stat($magic_stream);
					if (@$stat['size'] > 0 || @$stat['mtime'] > 0 || @$stat['mode'] > 0)
						{
						$this->destroy_ftp_resource($magic_stream->id);
						return false;
						}
					break;

				case 'c+':
				case 'c+b':
				case 'c+t':
					$this->resources[$magic_stream->id]['read'] = true;
				case 'c':
				case 'cb':
				case 'ct':
					$this->resources[$magic_stream->id]['write'] = true;
					//???
					break;

				default:
					$this->destroy_ftp_resource($magic_stream->id);
					return false;
				}
			return true;
			}
		$this->destroy_ftp_resource($magic_stream->id);
		return false;
		}

	public function stream_read($count,Magic $magic_stream)
		{
		if (!$this->resources[$magic_stream->id]['read'] || $count <= 0)
			return false;
		$output = '';
		$bytes_read = 0;
		$bytes_already_measured = 0;
		if (isset($this->resources[$magic_stream->id]['read_buffer'])) // we were already reading
			{
			$buffer = $this->resources[$magic_stream->id]['read_buffer'][0];
			$status = $this->resources[$magic_stream->id]['read_buffer'][1];
			if ($this->resources[$magic_stream->id]['read_buffer'][2] !== false)
				{
				$output = $this->resources[$magic_stream->id]['read_buffer'][2];
				$bytes_read = strlen($output);
				$bytes_already_measured = $bytes_read;
				}
			if ($status === FTP_MOREDATA && $count > $bytes_read)
				{
				if (isset($this->resources[$magic_stream->id]['measurements']))
					$start = microtime(true);
				$status = ftp_nb_continue($this->resources[$magic_stream->id]['connection']);
				}
			unset($this->resources[$magic_stream->id]['read_buffer']);
			}
		else
			{
			$buffer = fopen('php://temp','w+b');
			if (isset($this->resources[$magic_stream->id]['measurements']))
				$start = microtime(true);
			$status = @ftp_nb_fget($this->resources[$magic_stream->id]['connection'],$buffer,$this->options['directory'].$this->resources[$magic_stream->id]['path'],FTP_BINARY,$this->resources[$magic_stream->id]['pointer']);
			if ($status === FTP_FAILED)
				{
				if ($this->options['exception_on_read_error'] && $this->resources[$magic_stream->id]['pointer'] === 0)
					throw new Exception('FTP connection to '.$this->options['host'].' failed.',202);
				return false;
				}
			}
		while ($bytes_read < $count)
			{
			rewind($buffer);
			$chunk = stream_get_contents($buffer);
			ftruncate($buffer,0);
			$bytes_read += strlen($chunk);
			$output .= $chunk;
			if ($status !== FTP_MOREDATA)
				break;
			$status = ftp_nb_continue($this->resources[$magic_stream->id]['connection']);
			}
		if (isset($this->resources[$magic_stream->id]['measurements']) && isset($start))
			$this->measurements($magic_stream->id,'download',$bytes_read-$bytes_already_measured,microtime(true)-$start);
		// if only ftp_nb_cancel_transfer() existed
		if ($status === FTP_MOREDATA || $bytes_read > $count)
			$this->resources[$magic_stream->id]['read_buffer'] = [$buffer,$status,substr($output,$count)];
		else
			fclose($buffer);
		$this->resources[$magic_stream->id]['pointer'] += $count;
		return $bytes_read <= $count ? $output : substr($output,0,$count);
		}

	public function stream_eof(Magic $magic_stream)
		{
		$stat = $this->stream_stat($magic_stream);
		return $this->resources[$magic_stream->id]['pointer'] >= $stat['size'];
		}

	public function stream_stat(Magic $magic_stream)
		{
		return $this->url_stat(['path'=>$this->resources[$magic_stream->id]['path']],0,$magic_stream);
		}

	public function stream_seek($offset,$whence,Magic $magic_stream)
		{
		if (isset($this->resources[$magic_stream->id]['read_buffer']))
			$this->refresh_ftp_resource($magic_stream->id);
		switch ($whence)
			{
			case SEEK_SET:
				$this->resources[$magic_stream->id]['pointer'] = $offset;
				break;
			case SEEK_CUR:
				$this->resources[$magic_stream->id]['pointer'] += $offset;
				break;
			case SEEK_END; // EOF + offset
				$stat = $this->stream_stat($magic_stream);
				if (!empty($stat) && $stat['size'] > 0)
					{
					$this->resources[$magic_stream->id]['pointer'] = $stat['size']+$offset;
					break;
					}
			default:
				return false;
			}
		if ($this->resources[$magic_stream->id]['pointer'] < 0)
			$this->resources[$magic_stream->id]['pointer'] = 0;
		return true;
		}

	public function stream_tell(Magic $magic_stream)
		{
		return $this->resources[$magic_stream->id]['pointer'];
		}

	public function stream_truncate($new_size,Magic $magic_stream)
		{
		if (!$this->resources[$magic_stream->id]['write'])
			return false;
		$stat = $this->stream_stat($magic_stream);
		if ($stat['size'] > 0)
			{
			$original_pointer = $this->resources[$magic_stream->id]['pointer'];
			$data = '';
			if ($new_size > $stat['size'])
				$data = str_repeat("\x00",$new_size-$stat['size']); // get ready for memory exhaustion
			$this->resources[$magic_stream->id]['pointer'] = $new_size;
			$result = false;
			try
				{
				//TODO- it might just be better to write zeroes in batches to prevent hitting the memory limit for large sizes
				$result = $this->stream_write($data,$magic_stream); // this works because FTP does not support writing in the middle of files, everything coming after REST is discarded.
				}
			catch (\Exception $e)
				{
				$result = false;
				}
			$this->resources[$magic_stream->id]['pointer'] = $original_pointer;
			return !!$result;
			}
		return false;
		}

	public function stream_write($data,Magic $magic_stream)
		{
		if (!$this->resources[$magic_stream->id]['write'])
			return 0;
		if (isset($this->resources[$magic_stream->id]['read_buffer']))
			$this->refresh_ftp_resource($magic_stream->id);
		$buffer = fopen('php://temp','w+b');
		fwrite($buffer,$data);
		rewind($buffer);
		$pointer = isset($this->resources[$magic_stream->id]['append_pointer']) ? 'append_pointer' : 'pointer'; // for 'a' modes
		if (isset($this->resources[$magic_stream->id]['measurements']))
			$start = microtime(true);
		if (@ftp_fput($this->resources[$magic_stream->id]['connection'],$this->options['directory'].$this->resources[$magic_stream->id]['path'],$buffer,FTP_BINARY,$this->resources[$magic_stream->id][$pointer]))
			{
			fclose($buffer);
			if (isset($this->resources[$magic_stream->id]['measurements']))
				$end = microtime(true);
			$bytes = strlen($data);
			$this->resources[$magic_stream->id][$pointer] += $bytes;
			if (isset($start,$end) && $bytes > 0)
				$this->measurements($magic_stream->id,'upload',$bytes,$end-$start);
			return $bytes;
			}
		fclose($buffer);
		return 0;
		}

	public function stream_set_option($option,$arg1,$arg2,Magic $magic_stream)
		{
		switch ($option)
			{			
			case STREAM_OPTION_READ_TIMEOUT:
				return ftp_set_option($this->resources[$magic_stream->id]['connection'],FTP_TIMEOUT_SEC,$arg1);
			case STREAM_OPTION_BLOCKING:
			case STREAM_OPTION_WRITE_BUFFER:
				return false;
			}
		return false;
		}

	public function stream_lock($operation,Magic $magic_stream)
		{
		return false;
		}

	public function stream_flush(Magic $magic_stream)
		{
		return false;
		}

	public function stream_cast($cast_as,Magic $magic_stream)
		{
		return isset($this->resources[$magic_stream->id]) ? $this->resources[$magic_stream->id]['connection'] : false;
		}

	public function unlink(array $path_info,Magic $magic_stream)
		{
		return ftp_delete($this->control_connection(),$this->options['directory'].(isset($path_info['path']) ? $path_info['path'] : ''));
		}

	public function url_stat(array $path_info,$flags,Magic $magic_stream)
		{
		$ftp = $this->control_connection();
		$path = $this->options['directory'].(isset($path_info['path']) ? str_replace("\x00",'',$path_info['path']) : '');
		$counter = 0;
		$stat =
			[
			0 => 0, // dev
			1 => 0, // ino
			2 => 0, // mode
			3 => 0,
			4 => 0, // uid
			5 => 0, // gid
			6 => 0,
			7 => 0, // size
			8 => 0,
			9 => 0, // mtime
			10 => 0, // ctime
			11 => 0,
			12 => 0,
			'dev' => 0, // device number
			'ino' => 0, // inode number
			'mode' => 0, // inode protection mode
			'nlink' => 0, // number of links
			'uid' => 0, // userid of owner
			'gid' => 0, // groupid of owner
			'rdev' => 0, // device type, if inode device
			'size' => -1, // size in bytes
			'atime' => 0, // time of last access (Unix timestamp)
			'mtime' => 0, // time of last modification (Unix timestamp)
			'ctime' => 0, // time of last inode change (Unix timestamp)
			'blksize' => 0, // blocksize of filesystem IO
			'blocks' => 0, // number of 512-byte blocks allocated
			];

		$mlst = @ftp_raw($ftp,'MLST '.$path); // first try MLST
		if (!empty($mlst) && !empty($mlst[0]))
			{
			if (substr(ltrim($mlst[0]),0,3) === '550')
				return false; // file does not exist
			if (!empty($mlst[1]) && preg_match_all('/([a-zA-Z0-9.]+)\=([^;]+)/',$mlst[1],$matches,PREG_SET_ORDER))
				{
				++$counter;
				$perms = null;
				foreach ($matches as $match)
					{
					switch (strtolower($match[1]))
						{
						case 'type':
							$stat['mode'] |= ($match[2] === 'dir' ? 0040000 : 0100000);
							break;
						case 'size':
							$stat['size'] = (int)$match[2];
							break;
						case 'modify':
							$tc = str_split($match[2],2);
							if (count($tc) > 6)
								$stat['mtime'] = gmmktime($tc[4],$tc[5],$tc[6],$tc[2],$tc[3],$tc[0].$tc[1]);
							break;
						case 'create':
							$tc = str_split($match[2],2);
							if (count($tc) > 6)
								$stat['ctime'] = gmmktime($tc[4],$tc[5],$tc[6],$tc[2],$tc[3],$tc[0].$tc[1]);
							break;
						case 'perm':
							if ($perms === null) // UNIX.mode takes precedence
								{
								// this will probably be for Windows
								$perms = 0;
								if (strpos($match[2],'r') !== false || strpos($match[2],'e') !== false)
									$perms |= 0444;
								if (strpos($match[2],'w') !== false || strpos($match[2],'c') !== false)
									$perms |= 0222;
								}
							break;
						case 'unix.mode':
							$perms = octdec($match[2]);
							break;
						case 'unix.uid':
							$stat['uid'] = (int)$match[2];
							break;
						case 'unix.gid':
							$stat['gid'] = (int)$match[2];
							break;
						}
					}
				$stat['mode'] |= $perms;
				}
			}
		if (empty($stat['mode']) || $stat['size'] === -1)
			{
			$list = @ftp_rawlist($ftp,$path,false); // and if that fails try LIST
			if (is_array($list))
				{
				if (empty($list[0]))
					return false; // file does not exist
				$split = preg_split('/\s+/',$list[0]); //TODO- this will probably not work for Windows FTP servers
				if (count($split) > 4)
					{
					++$counter;
					//TODO- how about other modes?
					if ($stat['size'] <= 0)
						$stat['size'] = (int)$split[4];
					$stat['uid'] = (int)$split[2];
					if (preg_match('/^([d\-l]?)([rwx\-]{9})$/',$split[0],$matches))
						{
						switch ($matches[1])
							{
							case 'd':
								$mode = 0040000; // directory
								break;
							case 'l':
								$mode = 0120000; // link
								break;
							default:
								$mode = 0100000; // regular file
								break;
							}
						if ($matches[2][0] === 'r')
							$mode |= 0000400;
						if ($matches[2][1] === 'w')
							$mode |= 0000200;
						if ($matches[2][2] === 'x')
							$mode |= 0000100;
						elseif ($matches[2][2] === 's')
							$mode |= 0004100;
						elseif ($matches[2][2] === 'S')
							$mode |= 0004000;
						if ($matches[2][3] === 'r')
							$mode |= 0000040;
						if ($matches[2][4] === 'w')
							$mode |= 0000020;
						if ($matches[2][5] === 'x')
							$mode |= 0000010;
						elseif ($matches[2][5] === 's')
							$mode |= 0002010;
						elseif ($matches[2][5] === 'S')
							$mode |= 0002000;
						if ($matches[2][6] === 'r')
							$mode |= 0000004;
						if ($matches[2][7] === 'w')
							$mode |= 0000002;
						if ($matches[2][8] === 'x')
							$mode |= 0000001;
						elseif ($matches[2][2] === 't')
							$mode |= 0001001;
						elseif ($matches[2][2] === 'T')
							$mode |= 0001000;
						$stat['mode'] = $mode;
						}
					}
				}
			}
		if ($counter === 0 || $stat['size'] === -1)
			{
			$stat['size'] = @ftp_size($ftp,$path);
			if ($stat['size'] === -1)
				return false; // FTP commands failed somehow. User connection limit reached?
			if ($stat['mtime'] <= 0)
				$stat['mtime'] = @ftp_mdtm($ftp,$path);
			}
		$stat[2] = $stat['mode'];
		$stat[4] = $stat['uid'];
		$stat[5] = $stat['gid'];
		$stat[7] = $stat['size'];
		$stat[9] = $stat['mtime'];
		return $stat;
		}

	public function stream_metadata(array $path_info,$option,$value,Magic $magic_stream)
		{
		switch ($option)
			{
			case STREAM_META_TOUCH:
				if ($this->stream_open($path_info,'a',null,$opened_path,$magic_stream))
					{
					$this->stream_write('',$magic_stream);
					$this->destroy_ftp_resource($magic_stream->id);
					}
				break;
			case STREAM_META_OWNER_NAME:
			case STREAM_META_OWNER:
			case STREAM_META_GROUP_NAME:
			case STREAM_META_GROUP:
				return false;
			case STREAM_META_ACCESS:
				return ftp_chmod($this->control_connection(),$value,$this->options['directory'].(isset($path_info['path']) ? $path_info['path'] : '')) !== false;
			}
		return false;
		}

	public function stream_close(Magic $magic_stream)
		{
		return $this->destroy_ftp_resource($magic_stream->id);
		}

	public function mkdir(array $path_info,$mode,$options,Magic $magic_stream)
		{
		return ftp_mkdir($this->control_connection(),$this->options['directory'].(isset($path_info['path']) ? $path_info['path'] : '')) !== false;
		}

	public function rmdir(array $path_info,$options,Magic $magic_stream)
		{
		return ftp_rmdir($this->control_connection(),$this->options['directory'].(isset($path_info['path']) ? $path_info['path'] : ''));
		}

	public function rename(array $path_info_from,array $path_info_to,Magic $magic_stream)
		{
		return ftp_rename($this->control_connection(),$this->options['directory'].(isset($path_info_from['path']) ? $path_info_from['path'] : ''),$this->options['directory'].(isset($path_info_to['path']) ? $path_info_to['path'] : ''));
		}

	public function dir_opendir(array $path_info,$options,Magic $magic_stream)
		{
		$this->directories[$magic_stream->id] = ftp_nlist($this->control_connection(),$this->options['directory'].(isset($path_info['path']) ? $path_info['path'] : ''));
		if (!$this->directories[$magic_stream->id])
			{
			unset($this->directories[$magic_stream->id]);
			return false;
			}
		return true;
		}

	public function dir_closedir(Magic $magic_stream)
		{
		unset($this->directories[$magic_stream->id]);
		return true;
		}

	public function dir_readdir(Magic $magic_stream)
		{
		$current = current($this->directories[$magic_stream->id]);
		next($this->directories[$magic_stream->id]);
		return $current;
		}

	public function dir_rewinddir(Magic $magic_stream)
		{
		reset($this->directories[$magic_stream->id]);
		return true;
		}
	}