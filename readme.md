#MagicMounter

**MagicMounter** , by Marvin Janssen (http://marvinjanssen.me), released in 2017.

**MagicMounter** can magically mount "anything". These mounts can then be universally accessed using any of PHP's file/stream functions via the `magic://` stream wrapper. What
makes MagicMounter unique is that it the underlying driver for a mount is transparent to the end-user. Thus, you may at one point mount a local directory at `magic://production`, and later mount a remote directory via FTP at the same mount point.

Because **MagicMounter** works as a stream wrapper, all the goodness that comes with streams is available. Think of all the stream functions, filters, iterators, and so forth. This fact arguably makes it one of the most powerful PHP FTP clients to boot, whilst staying rather succinct.

To mount:

```php
Magic::mount(string $name,string $type,array $options);
```

Where:

- `$name`: the name of the mount (as magic://name).
- `$type`: the type of mount, which defines which underlying driver to load (e.g.: `'fs'`, `'ftp'`, `'ftps'`).
- `$options`: optional driver-specific options, see the driver classes for more information.


# Example code

```php
// Local filesystem:

Magic::mount('backup','fs',['directory'=>'/media/backup']);

copy('./index.php','magic://backup/index.php');

Magic::unmount('backup');

// FTP:

Magic::mount('production','ftp',
	[
	'host' => 'ftp.example.com',
	'username' => 'user',
	'password' => 'password',
	'directory' => '/var/www'
	]);

copy('./index.php','magic://production/index.php');

Magic::unmount('production');
```


# Drivers

**MagicMounter** currently comes with 3 drivers.

## fs

Local filesystem driver.

Options:

- `directory`: local directory to mount.


## ftp

Standard FTP driver using built-in `ftp_*` functions. The driver implements as many file system features as the PHP FTP extension permits.

Options:

- `username`: FTP username (default `'anonymous'`).
- `password`: FTP password (default `''`).
- `port`: FTP port (default `21`).
- `directory`: Optional directory (default `'/'`).
- `timeout`: FTP connection timeout (default `90`).
- `measure_transfer`: Attempt to measure transfers (default `false`).
- `exception_on_read_error`:  Throw an exception if reading fails (default `false`).

To retrieve measurements if `measure_transfer` is enabled, use:

```php
$resource = fopen('magic://mount/file.ext','r');

// ...

Magic::quote($resource,'download_speed');
Magic::quote($resource,'bytes_downloaded');
```


## ftps

Extends `ftp` but uses `ftp_ssl_connect()`.

Options are the same as `ftp`.


# Extending

Extending MagicMounter with your own drivers is easy. Create your own class in the `MagicMounter\driver` namespace and implement the `MagicMounter\Driver` interface. A good starting point is the `MagicMounter\driver\Fs` class. Having some knowledge of the PHP stream wrapper is recommended. Also, be sure that you implement as much as you can, to offer a well-rounded magic driver. (Support for `fseek()`, `fopen()`, `touch()`, `rename()`, `stat()`, `fopen()` modes, etc..)

You define any custom driver as long as it implements `MagicMounter\Driver`:

```php
Magic::driver('custom','\My\CustomDriver');

Magic::mount('production','custom');
```
