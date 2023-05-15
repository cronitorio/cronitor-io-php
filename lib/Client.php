<?php

namespace Cronitor;

use Exception;

class Client
{
    const MONITOR_TYPES = ['job', 'heartbeat', 'check'];
    const BASE_CONFIG_KEYS = ['apiKey', 'apiVersion', 'environment'];
    const DEFAULT_CONFIG_PATH = './cronitor.yaml';
    public $config;
    public $apiKey;
    public $apiVersion;
    public $monitors;
    public $environment;

    public function __construct($apiKey, $apiVersion = null, $environment = null)
    {
        $this->apiKey = $apiKey ?: getenv('CRONITOR_API_KEY');
        $this->apiVersion = $apiVersion ?: getenv('CRONITOR_API_VERSION');
        $this->environment = $environment ?: getenv('CRONITOR_ENVIRONMENT') ?: null;
        $this->config = getenv('CRONITOR_CONFIG') ?: null;
        if ($this->config !== null) {
            $this->readConfig();
        }

        $this->monitors = new Monitors($this->apiKey, $this->apiVersion);
    }

    public function monitor($key)
    {
        return new Monitor($key, $this->apiKey, $this->apiVersion, $this->environment);
    }

    public function readConfig($path = null, $output = false)
    {
        $this->config = $path ?: $this->config;
        if (!$this->config) {
            throw new ConfigurationException("Must include a path by passing a path to readConfig e.g. \$cronitor->readConfig('./cronitor.yaml')");
        }

        $conf = \Symfony\Component\Yaml\Yaml::parseFile($this->config);

        $configKeys = self::getConfigKeys();
        foreach ($conf as $k => $v) {
            if (!in_array($k, $configKeys)) {
                throw new ConfigurationException("Invalid configuration variable: $k");
            }
        }

        $this->apiKey = isset($conf['apiKey']) ? $conf['apiKey'] : $this->apiKey;
        $this->apiVersion = isset($conf['apiVersion']) ? $conf['apiVersion'] : $this->apiVersion;
        $this->environment = isset($conf['environment']) ? $conf['environment'] : $this->environment;

        if (!$output) {
            return;
        }

        $monitors = [];
        foreach (self::MONITOR_TYPES as $t) {
            $pluralType = $this->pluralizeType($t);
            $toParse = isset($conf[$t]) ? $conf[$t] : (isset($conf[$pluralType]) ? $conf[$pluralType] : null);
            if (!$toParse) {
                continue;
            }

            if (array_keys($toParse) === range(0, count($toParse) - 1)) {
                throw new ConfigurationException('An associative array with keys corresponding to monitor keys is expected.');
            }

            foreach ($toParse as $key => $m) {
                $m['key'] = $key;
                $m['type'] = $t;
                array_push($monitors, $m);
            }
        }

        $conf['monitors'] = $monitors;
        return $conf;
    }

    public function applyConfig($rollback = false)
    {
        try {
            $conf = $this->readConfig(null, true);
            $params = [
                'monitors' => isset($conf['monitors']) ? $conf['monitors'] : [],
                'rollback' => $rollback,
            ];
            $monitors = $this->monitors->put($params);
            echo count($monitors) . " monitors " . ($rollback ? 'validated' : 'synced to Cronitor');
            return true;
        } catch (ValidationException $e) {
            \error_log($e, 0);
        }
    }

    public function validateConfig()
    {
        return $this->applyConfig(true);
    }

    public function generateConfig()
    {
        $configPath = $this->config ?: self::DEFAULT_CONFIG_PATH;
        $file = fopen($configPath, 'w');
        try {
            $config = Monitor::getYaml($this->apiKey, $this->apiVersion);
            fwrite($file, $config);
        } finally {
            fclose($file);
        }
        return true;
    }

    /**
     * @throws Exception
     */
    public function job($key, $callback)
    {
        $monitor = $this->monitor($key);
        $series = microtime(true);
        $monitor->ping(['state' => 'run', 'series' => $series]);

        try {
            $callbackValue = $callback();
            $monitor->ping(['state' => 'complete', 'series' => $series]);
            return $callbackValue;
        } catch (Exception $e) {
            $message = $e->getMessage();
            $truncatedMessage = substr($message, abs(min(0, 1600 - strlen($message))));
            $monitor->ping([
                'state' => 'fail',
                'message' => $truncatedMessage,
                'series' => $series,
            ]);
            throw $e;
        }
    }

    private static function pluralizeType($type)
    {
        return $type . 's';
    }

    private static function getConfigKeys()
    {
        return array_merge(
            self::BASE_CONFIG_KEYS,
            array_map('self::pluralizeType', self::MONITOR_TYPES)
        );
    }
}
