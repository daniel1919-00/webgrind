<?php
/**
 * @author Jacob Oettinger
 * @author Joakim Nygård
 */

use Webgrind\Config;
use Webgrind\library\FileHandler;

define('PATH_ROOT', realpath(rtrim(__DIR__, DIRECTORY_SEPARATOR)));

if (is_file('vendor'))
{
    require PATH_ROOT . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
}
else
{
    spl_autoload_register(static function ($className)
    {
        require PATH_ROOT . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, str_replace('Webgrind\\', '', ltrim($className, '\\'))) . '.php';
    });
}

set_time_limit(0);
if (ini_get('date.timezone') == '')
{
    date_default_timezone_set(Config::$defaultTimezone);
}

try
{
    switch (get('op'))
    {
        case 'file_list':
            sendJson(FileHandler::getInstance()->getTraceList());
            break;

        case 'function_list':
            $dataFile = get('dataFile');
            if ($dataFile == '0')
            {
                $files = FileHandler::getInstance()->getTraceList();
                $dataFile = $files[0]['filename'];
            }
            $reader = FileHandler::getInstance()->getTraceReader($dataFile, get('costFormat', Config::$defaultCostformat));
            $functions = array();
            $shownTotal = 0;
            $breakdown = array('internal' => 0, 'procedural' => 0, 'class' => 0, 'include' => 0);

            for ($i = 0; $i < $reader->getFunctionCount(); $i++)
            {
                $functionInfo = $reader->getFunctionInfo($i);

                if (str_contains($functionInfo['functionName'], 'php::'))
                {
                    $breakdown['internal'] += $functionInfo['summedSelfCostRaw'];
                    $humanKind = 'internal';
                }
                else
                {
                    if (str_contains($functionInfo['functionName'], 'require_once::') ||
                        str_contains($functionInfo['functionName'], 'require::') ||
                        str_contains($functionInfo['functionName'], 'include_once::') ||
                        str_contains($functionInfo['functionName'], 'include::'))
                    {
                        $breakdown['include'] += $functionInfo['summedSelfCostRaw'];
                        $humanKind = 'include';
                    }
                    else
                    {
                        if (str_contains($functionInfo['functionName'], '->') || str_contains($functionInfo['functionName'], '::'))
                        {
                            $breakdown['class'] += $functionInfo['summedSelfCostRaw'];
                            $humanKind = 'class';
                        }
                        else
                        {
                            $breakdown['procedural'] += $functionInfo['summedSelfCostRaw'];
                            $humanKind = 'procedural';
                        }
                    }
                }
                if (!(int)get('hideInternals', 0) || !str_contains($functionInfo['functionName'], 'php::'))
                {
                    $shownTotal += $functionInfo['summedSelfCostRaw'];
                    $functions[$i] = $functionInfo;
                    $functions[$i]['nr'] = $i;
                    $functions[$i]['humanKind'] = $humanKind;
                }
            }
            usort($functions, 'costCmp');

            $remainingCost = $shownTotal * get('showFraction');

            $result['functions'] = array();
            foreach ($functions as $function)
            {
                $remainingCost -= $function['summedSelfCostRaw'];
                $function['file'] = urlencode($function['file']);
                $result['functions'][] = $function;
                if ($remainingCost < 0)
                {
                    break;
                }
            }
            $result['summedInvocationCount'] = $reader->getFunctionCount();
            $result['summedRunTime'] = $reader->formatCost($reader->getHeader('summary'), 'msec');
            $result['dataFile'] = $dataFile;
            $result['invokeUrl'] = $reader->getHeader('cmd');
            $result['runs'] = $reader->getHeader('runs');
            $result['breakdown'] = $breakdown;
            $result['mtime'] = date(Config::$dateFormat, filemtime(Config::xdebugOutputDir() . $dataFile));

            $creator = preg_replace('/[^0-9.]/', '', $reader->getHeader('creator'));
            $result['linkToFunctionLine'] = version_compare($creator, '2.1') > 0;

            sendJson($result);
            break;

        case 'callinfo_list':
            $reader = FileHandler::getInstance()->getTraceReader(get('file'), get('costFormat', Config::$defaultCostformat));
            $functionNr = get('functionNr');
            $function = $reader->getFunctionInfo($functionNr);

            $result = array('calledFrom' => array(), 'subCalls' => array());
            $foundInvocations = 0;
            for ($i = 0; $i < $function['calledFromInfoCount']; $i++)
            {
                $invo = $reader->getCalledFromInfo($functionNr, $i);
                $foundInvocations += $invo['callCount'];
                $callerInfo = $reader->getFunctionInfo($invo['functionNr']);
                $invo['file'] = urlencode($callerInfo['file']);
                $invo['callerFunctionName'] = $callerInfo['functionName'];
                $result['calledFrom'][] = $invo;
            }
            $result['calledByHost'] = ($foundInvocations < $function['invocationCount']);

            for ($i = 0; $i < $function['subCallInfoCount']; $i++)
            {
                $invo = $reader->getSubCallInfo($functionNr, $i);
                $callInfo = $reader->getFunctionInfo($invo['functionNr']);
                $invo['file'] = urlencode($function['file']); // Sub call to $callInfo['file'] but from $function['file']
                $invo['callerFunctionName'] = $callInfo['functionName'];
                $result['subCalls'][] = $invo;
            }
            sendJson($result);
            break;

        case 'fileviewer':
            $file = get('file');

            $message = 'No file to view.';
            if ($file)
            {
                $message = '<code>' . htmlspecialchars($file) . '</code> is not readable. '
                    . 'Modify <code>exposeServerFile()</code> in <code>webgrind/Config.php</code> to grant access.';
                $file = Config::exposeServerFile($file);
                if ($file && is_file($file) && is_readable($file))
                {
                    // Access granted.
                    $message = '';
                }
            }
            require 'templates/fileviewer.phtml';
            break;

        case 'function_graph':
            $dataFile = get('dataFile');
            $showFraction = 100 - intval(get('showFraction') * 100);
            if ($dataFile == '0')
            {
                $files = FileHandler::getInstance()->getTraceList();
                $dataFile = $files[0]['filename'];
            }

            $filename = Config::storageDir() . $dataFile . '-' . $showFraction . Config::$preprocessedSuffix . '.' . Config::$graphImageType;
            if (!file_exists($filename))
            {
                // Add enclosing quotes if needed
                foreach (array('pythonExecutable', 'dotExecutable') as $exe)
                {
                    $item =& Config::$$exe;
                    if (str_contains($item, ' ') && !preg_match('/^".+"$/', $item))
                    {
                        $item = '"' . $item . '"';
                    }
                }
                shell_exec(Config::$pythonExecutable . ' library/gprof2dot.py -n ' . $showFraction
                    . ' -f callgrind ' . escapeshellarg(Config::xdebugOutputDir() . $dataFile) . ' | '
                    . Config::$dotExecutable . ' -T' . Config::$graphImageType . ' -o ' . escapeshellarg($filename));
            }

            if (!file_exists($filename))
            {
                $file = $filename;
                $message = 'Unable to generate <u>' . $file . '</u> via python: <u>' . Config::$pythonExecutable
                    . '</u> and dot: <u>' . Config::$dotExecutable . '</u>. Please update Config.php.';
                require 'templates/fileviewer.phtml';
                break;
            }

            if (Config::$graphImageType == 'svg')
            {
                header('Content-Type: image/svg+xml');
            }
            else
            {
                header('Content-Type: image/' . Config::$graphImageType);
            }
            readfile($filename);
            break;

        case 'version_info':
            $response = @file_get_contents('http://jokkedk.github.io/webgrind/webgrindupdate.json?version=' . Config::$webgrindVersion);
            if ($response)
            {
                header('Content-type: application/json');
                echo $response;
            }
            break;

        case 'download_link':
            $file = Config::exposeServerFile(Config::xdebugOutputDir() . get('file'));

            if (empty($file))
            {
                sendJson(array('error' => 'No file found or access denied!'));
                exit;
            }

            $params = array('op' => 'download_file', 'file' => get('file'));
            sendJson(array('done' => '?' . http_build_query($params)));
            break;

        case 'download_file':
            $file = Config::exposeServerFile(Config::xdebugOutputDir() . get('file'));

            if (empty($file))
            {
                exit;
            }

            header('Cache-Control: public');
            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename=' . get('file'));
            header('Content-Type: text/plain');
            header('Content-Transfer-Encoding: binary');

            readfile($file);
            break;

        case 'clear_files':
            $files = FileHandler::getInstance()->getTraceList();
            if (!$files)
            {
                sendJson(array('done' => 'no files found'));
                break;
            }
            $format = array();
            foreach ($files as $file)
            {
                unlink(Config::xdebugOutputDir() . $file['filename']);
                $format[] = preg_quote($file['filename'], '/');
            }
            $files = preg_grep('/' . implode('|', $format) . '/', scandir(Config::storageDir()));
            foreach ($files as $file)
            {
                unlink(Config::storageDir() . $file);
            }
            sendJson(array('done' => true));
            break;

        default:
            $welcome = '';
            if (!file_exists(Config::storageDir()) || !is_writable(Config::storageDir()))
            {
                $welcome .= 'Webgrind $storageDir does not exist or is not writeable: <code>' . Config::storageDir() . '</code><br>';
            }
            if (!file_exists(Config::xdebugOutputDir()) || !is_readable(Config::xdebugOutputDir()))
            {
                $welcome .= 'Webgrind $profilerDir does not exist or is not readable: <code>' . Config::xdebugOutputDir() . '</code><br>';
            }

            if ($welcome == '')
            {
                $welcome = 'Select a cachegrind file above<br>(looking in <code>' . Config::xdebugOutputDir() . '</code> for files matching <code>' . Config::xdebugOutputFormat() . '</code>)';
            }
            require 'templates/index.phtml';
    }
} catch (Exception $e)
{
    sendJson(array('error' => $e->getMessage() . '<br>' . $e->getFile() . ', line ' . $e->getLine()));
    return;
}

function get($param, $default = false)
{
    return ($_GET[$param] ?? $default);
}

function costCmp($a, $b): int
{
    $a = $a['summedSelfCostRaw'];
    $b = $b['summedSelfCostRaw'];

    if ($a == $b)
    {
        return 0;
    }
    return ($a > $b) ? -1 : 1;
}

function sendJson($object): void
{
    header('Content-type: application/json');
    echo json_encode($object);
}
