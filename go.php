#!/usr/bin/php
<?php

/**
 * GoSSH PHP.  An SSH connection manager written in PHP.
 *
 * @author  Drew Phillips <drew@drew-phillips.com>
 * @version 1.0.0
 * @license BSD-3-Clause
 * @link https://github.com/dapphp/gossh-php
 *
 *  Copyright (c) 2017 Drew Phillips
 *  All rights reserved.
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 *
 *  - Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *  - Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 *  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 *  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 *  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 *  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 *  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *  POSSIBILITY OF SUCH DAMAGE.
 */



/** @var string The name of the config file used to store host entries */
define('CONFIG_FILE_NAME', '.gohosts.cfg.php');

if (php_sapi_name() != 'cli') {
    echo "This can only be run from a shell!\n";
    exit(1);
}

// command line option parsing
$shortopts = 'Aavp:u:';
$longopts  = [ 'user:', 'port:', 'add', 'name:' ];

// parse parameters
$options = getopt($shortopts, $longopts);

// remaining arg, should be the server name to connect to
$newopts = getopt_remaining($options);

// testing, uncomment to debug parsed options
//var_dump($options, $newopts);

$config     = getHostsFromConfig();
$hosts      = array();
$identities = array();

if (is_array($config)) {
    $hosts      = (isset($config['hosts']) && is_array($config['hosts'])) ? $config['hosts'] : array();
    $identities = (isset($config['identities']) && is_array($config['identities'])) ? $config['identities'] : array();
}

if (sizeof($newopts) > 1) {
    echo "Too many remaining arguments, couldn't determine host\n";

    echo usage();
    exit(1);
} elseif (sizeof($newopts) == 0) {
    echo usage();
    exit(1);
}

$host = $newopts[0];
$user = $port = $verbose = null;

if (strpos($host, '@') !== false) {
    list($user, $host) = explode('@', $host, 2);
}

if (isset($options['u'])) {
    $user = $options['u'];
} elseif (isset($options['user'])) {
    $user = $options['user'];
}

if (isset($options['p'])) {
    $port = $options['p'];
} elseif (isset($options['port'])) {
    $port = $options['port'];
}

if (isset($hosts[$host])) {
    $h = $hosts[$host];
    $user = ($user) ?: $h[0];
    $host = $h[1];
    $port = ($port) ?: $h[2];
    $fwd  = (isset($h[3]) && true == $h[3]) ? true : false;
} else {
    $fwd = false;
}

if (isset($options['v']))
    $verbose = true;

if (isset($options['A']))
    $fwd = true;

if (isset($options['a']))
    $fwd = false;

if (isset($options['add'])) {
    // add host
    if (!isset($options['name'])) {
        echo "'--name' must be supplied when calling --add\n";
        echo usage();
        exit(1);
    }

    $entry = array($user, $host, $port, (int)$fwd);

    if (addHostToConfigFile($options['name'], $entry)) {
        exit(128);
    }

    exit(2);
}

$cmd = 'ssh %s%s %s';
$cmd = sprintf($cmd,
               (!$user ? '' : "{$user}@"),
               $host,
               (!$port ? '' : "-p {$port}")
);

if ($verbose) $cmd .= ' -v';
if ($fwd)     $cmd .= ' -A';

echo $cmd . "\n";
exit(0);
//passthru($cmd);


function usage()
{
    global $argv, $hosts;

    $script = basename($argv[0]);

    echo <<<USE
Usage: {$script} [OPTIONS] HOST
Quick SSH connection to HOST.
Example: {$script} -u prog host.example.org
         {$script} hostname
         {$script} --add --name example -u myuser -A -p 2222 hostname.example.org

Options:

    -A                Enables forwarding of the authentication agent connection
    -a                Disables forwarding of the authentication agent connection
    -u, --user        User to connect as
    -p, --port        Port to connect to
    -v,               Enable verbose SSH output
        --add         Flag to add a new host
        --name name   Save the connection as 'name'

Hosts:

USE;

    if (!empty($hosts) && sizeof($hosts) > 0) {
        ksort($hosts);

        $len = 0;

        foreach ($hosts as $name => $host) {
            if (strlen($name) > $len) {
                $len = strlen($name);
            }
        }

        foreach ($hosts as $name => $host) {
            echo sprintf("  %{$len}s => %s@%s%s\n", $name, $host[0], $host[1], ($host[2]) ? ":{$host[2]}" : '');
        }
    } else {
        echo "\n"
            ."    No hosts defined in " . CONFIG_FILE_NAME . "!\n"
            ."    See docs for more info.\n";
    }

    echo <<<BANNER

Report bugs to : drew@drew-phillips.com
Homepage       : https://drew-phillips.com
Download       : https://github.com/dapphp/gossh-php

BANNER;

}

function getopt_remaining($options)
{
    global $argc, $argv;

    $newargv = []; // array of unparsed params from getopt

    // loop over args, starting from 1
    for ($i = 1; $i < $argc; ++$i) {
        if (substr($argv[$i], 0, 2) == '--') { // long option
            $opt = substr($argv[$i], 2);
        } elseif (substr($argv[$i], 0, 1) == '-') { // short option
            $opt = substr($argv[$i], 1, 1);
        } else { // option argument
            $opt = null;
        }

        if (!isset($options[$opt])) {
            // option not in parsed args - append to newargv
            $newargv[] = $argv[$i];
        } elseif (strpos($argv[$i], $options[$opt]) !== false) {
            // short arg with no space and a value (i.e. -xVALUE
            continue;
        } elseif (!is_bool($options[$opt])) {
            // $opt is short arg w/ value - implies (-x VALUE); skip next $argv which is VALUE
            $i++;
        }
    }

    // $newargv now contains all unprocessed values from $argv
    return $newargv;
}

function findConfigFile()
{
    $home = realpath(getenv('HOME'));

    if (!$home) {
        $home = (isset($_SERVER['HOME'])) ? realpath($_SERVER['HOME']) : null;
    }

    if (!$home) {
        $home = realpath(__DIR__);
    }

    $cfg = realpath($home . DIRECTORY_SEPARATOR . CONFIG_FILE_NAME);

    return $cfg;
}

function getHostsFromConfig()
{
    $config = null;
    $cfg    = findConfigFile();

    if (file_exists($cfg)) {
        try {
            ob_start();
            $config = include $cfg;
            $output = trim(ob_get_contents());
            ob_end_clean();

            if (strlen($output)) {
                echo "Warning: config file '$cfg' produced output\n";
            }

            if (!is_array($config)) {
                echo "Warning: config file '$cfg' did not return an array!\n";
                $config = null;
            }
        } catch (\Exception $ex) {
            echo "Config is borked\n";
        }
    }

    return $config;
}

function addHostToConfigFile($name, $entry)
{
    $cfg = findConfigFile();

    if (!file_exists($cfg)) {
        echo "Config file '$cfg' does not exist or is not readable.\n";
        return false;
    }

    if (!is_writable($cfg)) {
        echo "Config file '$cfg' is not writable.\n";
        return false;
    }


    // make backup
    $path = dirname($cfg);
    $dest = $path . DIRECTORY_SEPARATOR . CONFIG_FILE_NAME . '.bak';

    if (!copy($cfg, $dest)) {
        echo "Failed to make backup of config file.\n";
        return false;
    }

    $existing = getHostsFromConfig();
    $existing['hosts'][$name] = $entry;

    $content = file_get_contents($cfg);
    $pos     = strrpos($content, 'return [');

    if ($pos === false)
        $pos = strrpos($content, 'return array');

    if ($pos === false) {
        echo "Failed to locate 'return' statement that defines host entries!\n";
        return false;
    }

    $content = substr($content, 0, $pos);

    $fp = fopen($cfg, 'w+');

    if (!$fp) {
        echo "Failed to open '$cfg' for writing\n";
        return false;
    }

    // write out existing contents of the file up to the final 'return' statement
    fwrite($fp, $content . "return array(\n");

    // write 'hosts' key of array
    fwrite($fp, "    'hosts' => array(\n");

    // write out each host, one per line, formatted
    foreach($existing['hosts'] as $name => $entry) {
        $line  = sprintf('        %-20s => array(', "'{$name}'");
        $line .= sprintf("'%s', '%s', %s, %d", $entry[0], $entry[1], var_export($entry[2], true), var_export($entry[3], true));
        $line .= "),\n";

        fwrite($fp, $line);
    }

    // close the 'hosts' array entry
    fwrite($fp, "    ),\n");

    // write 'identities' key of array
    fwrite($fp, "    'identities' => array(\n");

    // write out each identity, one per line, formatted
    if (isset($existing['identities'])) {
        foreach($existing['identities'] as $name => $entry) {
            $line  = sprintf("        %-20s => %s,\n", "'{$name}'", var_export($entry, true));

            fwrite($fp, $line);
        }
    }

    // close the 'identities' array entry
    fwrite($fp, "    ),\n");

    // close the array and terminate return statement
    fwrite($fp, ");\n");

    fclose($fp);

    echo "Host entry added.  Old config file backed up to '{$dest}'\n";

    return true;
}

