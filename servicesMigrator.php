<?php

namespace Xircl;

use Symfony\Component\Yaml\Yaml;

class ServicesMigrator
{
    const TOKEN = 'asdfasdfasdfkwqjck';

    /** @var string */
    private $rootDirectory;

    /** @var array|false */
    private $directories;

    /** @var array */
    private $resultTree = [];

    private $services = [];

    private $multiInstantiatedClasses = [];

    public function __construct(string $workingDirectory)
    {
        $timeStart = microtime(true);
        $this->rootDirectory = $workingDirectory;
        $this->directories = $this->getDirTree("{$workingDirectory}/src/Xircl");
        $this->directories['config'] = $this->getDirTree("{$workingDirectory}/app/config", true);
        $this->resultTree = $this->directories;
        $this->collectServices($this->directories);
        $count = count($this->services);
        $origCount = $count;
        foreach ($this->services as $serviceDefinition => $class) {
            $this->resultTree = $this->processService($serviceDefinition, $class, $this->resultTree);
            echo $serviceDefinition." processed... ".round($count / $origCount * 100)."\n";
            $count--;
        };
        $this->save($this->resultTree);
        echo 'Total execution time in seconds: '.(microtime(true) - $timeStart)."\n";
    }

    public function save($array)
    {
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                if (isset($item[self::TOKEN]) && $item['changed'] === true) {
                    $this->editFile($item['path'], $item['data']);
                    echo $item['path']." saved\n";
                } else {
                    $this->save($item);
                }
            }
        }
    }

    public function collectServices($array)
    {
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                if (isset($item[self::TOKEN])) {
                    if ($item['yml'] === false) {
                        continue;
                    }
                    if (isset($item['data']['services'])) {
                        foreach (array_keys($item['data']['services']) as $serviceDefinition) {
                            if (isset($item['data']['services'][$serviceDefinition]['class'])) {
                                $className = $item['data']['services'][$serviceDefinition]['class'];
                                if (stripos($className, 'xircl') !== false) {
                                    $this->services[$serviceDefinition] = $className;
                                    $count = $this->multiInstantiatedClasses[$className] ?? 0;
                                    $this->multiInstantiatedClasses[$className] = ++$count;
                                }
                            }
                        }
                    }
                } else {
                    $this->collectServices($item);
                }
            }
        }
    }

    public function processService(string $serviceDefinition, string $className, $array)
    {
        $result = [];
        $parts = explode('\\', $className);
        $shortClass = end($parts);
        foreach ($array as $key => $item) {
            if (isset($item[self::TOKEN])) {
                $data = $item['data'];
                if ($item['yml'] === true) {
                    $data = $this->yamlProcess($serviceDefinition, $className, $item, $data);
                } else {
                    if (!$className) {
                        continue;
                    }
                    $classContainService = false;
                    foreach ($data as &$row) {
                        if (strpos($row, $serviceDefinition)) {
                            $classContainService = true;
                            $row = str_replace(
                                ['"'.$serviceDefinition.'"', "'$serviceDefinition'"],
                                $shortClass.'::class',
                                $row
                            );
                        } else {
                            continue;
                        }
                    }
                    if ($classContainService) {
                        $namespaceLine = $firstUseLine = $lastUseLine = $hasClassName = false;
                        foreach ($data as $key1 => $row1) {
                            if (strpos($row1, 'use') !== false) {
                                if (strpos($row1, $className) !== false) {
                                    $hasClassName = true;
                                }
                                if (!$firstUseLine) {
                                    $firstUseLine = $key1;
                                    continue;
                                }
                            } elseif ($firstUseLine) {
                                $lastUseLine = $key1;
                                break;
                            }
                            if (!$namespaceLine && strpos($row1, 'namespace') !== false) {
                                $namespaceLine = $key1 + 2;
                            }
                        }
                        $lineBreak = $lastUseLine ? "\n" : "\n\n";
                        $lastUseLine = $lastUseLine ?: $namespaceLine;
                        if (!$hasClassName && $lastUseLine) {
                            array_splice($data, $lastUseLine, 0, 'use '.$className.";$lineBreak");
                        }
                    }
                }
                if ($item['data'] != $data) {
                    $item['changed'] = true;
                }
                $item['data'] = $data;
                $result[$key] = $item;
            } else {
                $result[$key] = $this->processService($serviceDefinition, $className, $item);
            }
        }

        return $result;
    }

    protected function rewriteServiceArray($array, $serviceDefinition, string $className)
    {
        $result = [];
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $this->rewriteServiceArray($item, $serviceDefinition, $className);
            } else {
                if ($item === $serviceDefinition) {
                    $result[$key] = $className;
                } elseif ($item === '@'.$serviceDefinition) {
                    $result[$key] = '@'.$className;
                } else {
                    $result[$key] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * @param string $path
     * @param array $replace
     */
    private function editFile(string $path, array $replace): void
    {
        if (pathinfo($path)['extension'] === 'yml') {
            $replace = Yaml::dump($replace, 4, 2);
        } else {
            $replace = implode('', $replace);
        }
        $resource = fopen($path, 'w');
        fwrite($resource, $replace);
        fclose($resource);
    }

    private function getDirTree($dir, $excludeDirs = false)
    {
        $files = array_map('basename', glob("$dir/*"));
        $return = [];
        foreach ($files as $file) {
            if (is_dir("$dir/$file")) {
                if ($excludeDirs) {
                    continue;
                }
                $return[$file] = $this->getDirTree("$dir/$file");
            } else {
                $result = $this->prepareFileData($dir, $file, $excludeDirs);
                $return[$file] = $result ? $this->prepareFileData($dir, $file, $excludeDirs) : [];
            }
        }

        return $return;
    }

    private function prepareFileData($dir, $filename, $excludeDirs = false)
    {
        $parts = explode('.', $filename);
        $ext = end($parts);
        $filePath = $dir.'/'.$filename;
        if ($ext === 'yml') {
            $data = Yaml::parseFile($filePath);
            if (!isset($data['services']) && !$excludeDirs) {
                return false;
            }
        } else {
            $data = file($filePath);
        }

        return [
            'path' => $filePath,
            'data' => $data,
            'yml' => $ext === 'yml',
            'changed' => false,
            self::TOKEN => true,
        ];
    }

    /**
     * @param string $serviceDefinition
     * @param string $className
     * @param $item
     * @param $data
     * @return array
     */
    private function yamlProcess(string $serviceDefinition, ?string $className, $item, $data): array
    {
        if (!isset($item['data']['services'])) {
            return $this->configYamlProcess($serviceDefinition, $className, $data);
        }
        if (isset($item['data']['services'][$serviceDefinition])) {
            if (!isset($item['data']['services'][$serviceDefinition]['public'])) {
                $item['data']['services'][$serviceDefinition]['public'] = true;
            }
            if ($className) {
                if (in_array($className, $this->multiInstantiatedClasses)
                    && $this->multiInstantiatedClasses[$className] > 1) {
                    return $data;
                }
                unset($data['services'][$serviceDefinition]);
                $data['services'][$className] = $item['data']['services'][$serviceDefinition];
                unset($data['services'][$className]['class']);
            } else {
                $data = $item['data'];
            }
        }
        if ($className) {
            $data['services'] = $this->rewriteServiceArray($data['services'], $serviceDefinition, $className);
        }

        return $data;
    }

    private function configYamlProcess(string $serviceDefinition, ?string $className, $data)
    {
        foreach ($data as $key => &$row) {
            if (is_array($row)) {
                $data[$key] = $this->configYamlProcess($serviceDefinition, $className, $row);
            } elseif (strpos($row, $serviceDefinition) !== false) {
                $row = str_replace(
                    $serviceDefinition,
                    $className,
                    $row
                );
            }
        }

        return $data;
    }
}
