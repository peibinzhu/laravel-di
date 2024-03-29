<?php

declare(strict_types=1);

namespace PeibinLaravel\Di\Annotation;

use Illuminate\Filesystem\Filesystem;
use PeibinLaravel\Di\Contracts\AnnotationInterface;
use PeibinLaravel\Di\Contracts\ScanHandlerInterface;
use PeibinLaravel\Di\Exceptions\DirectoryNotExistException;
use PeibinLaravel\Di\MetadataCollector;
use PeibinLaravel\Di\ReflectionManager;
use ReflectionClass;

class Scanner
{
    protected Filesystem $filesystem;

    protected string $path;

    /**
     * @param ScanConfig           $scanConfig
     * @param ScanHandlerInterface $handler
     */
    public function __construct(protected ScanConfig $scanConfig, protected ScanHandlerInterface $handler)
    {
        $this->filesystem = new Filesystem();
        $this->path = base_path('runtime/container/scan.cache');
    }

    public function scan()
    {
        $paths = $this->scanConfig->getPaths();
        $collectors = $this->scanConfig->getCollectors();
        if (!$paths) {
            return [];
        }

        $lastCacheModified = file_exists($this->path) ? $this->filesystem->lastModified($this->path) : 0;
        if ($lastCacheModified > 0 && $this->scanConfig->isCacheable()) {
            return $this->deserializeCachedScanData($collectors);
        }

        $scanned = $this->handler->scan();
        if ($scanned->isScanned()) {
            return $this->deserializeCachedScanData($collectors);
        }

        $this->deserializeCachedScanData($collectors);

        $annotationReader = new AnnotationReader();

        $paths = $this->normalizeDir($paths);

        $classes = ReflectionManager::getAllClasses($paths);

        $this->clearRemovedClasses($collectors, $classes);

        foreach ($classes as $className => $reflectionClass) {
            if ($this->filesystem->lastModified($reflectionClass->getFileName()) >= $lastCacheModified) {
                /** @var MetadataCollector $collector */
                foreach ($collectors as $collector) {
                    $collector::clear($className);
                }

                $this->collect($annotationReader, $reflectionClass);
            }
        }

        $data = [];
        /** @var MetadataCollector|string $collector */
        foreach ($collectors as $collector) {
            $data[$collector] = $collector::serialize();
        }

        $this->putCache($this->path, serialize([$data]));
        exit;
    }

    public function collect(AnnotationReader $reader, ReflectionClass $reflection): void
    {
        $className = $reflection->getName();
        if ($path = $this->scanConfig->getClassMap()[$className] ?? null) {
            if ($reflection->getFileName() !== $path) {
                // When the original class is dynamically replaced, the original class should not be collected.
                return;
            }
        }

        // Parse class annotations.
        $classAnnotations = $reader->getClassAnnotations($reflection);
        if (!empty($classAnnotations)) {
            foreach ($classAnnotations as $classAnnotation) {
                if ($classAnnotation instanceof AnnotationInterface) {
                    $classAnnotation->collectClass($className);
                }
            }
        }

        // Parse properties annotations.
        $properties = $reflection->getProperties();
        foreach ($properties as $property) {
            $propertyAnnotations = $reader->getPropertyAnnotations($property);
            if (!empty($propertyAnnotations)) {
                foreach ($propertyAnnotations as $propertyAnnotation) {
                    if ($propertyAnnotation instanceof AnnotationInterface) {
                        $propertyAnnotation->collectProperty($className, $property->getName());
                    }
                }
            }
        }

        // Parse methods annotations.
        $methods = $reflection->getMethods();
        foreach ($methods as $method) {
            $methodAnnotations = $reader->getMethodAnnotations($method);
            if (!empty($methodAnnotations)) {
                foreach ($methodAnnotations as $methodAnnotation) {
                    if ($methodAnnotation instanceof AnnotationInterface) {
                        $methodAnnotation->collectMethod($className, $method->getName());
                    }
                }
            }
        }

        unset($reflection, $classAnnotations, $properties, $methods);
    }

    /**
     * Normalizes given directory names by removing directory not exist.
     * @throws DirectoryNotExistException
     */
    public function normalizeDir(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $result[] = $path;
            }
        }

        if ($paths && !$result) {
            throw new DirectoryNotExistException('The scanned directory does not exist.');
        }

        return $result;
    }

    protected function deserializeCachedScanData(array $collectors): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        [$data] = unserialize(file_get_contents($this->path));
        foreach ($data as $collector => $deserialized) {
            /** @var MetadataCollector $collector */
            if (in_array($collector, $collectors)) {
                $collector::deserialize($deserialized);
            }
        }

        return $data;
    }

    /**
     * @param MetadataCollector[] $collectors
     * @param ReflectionClass[]   $reflections
     */
    protected function clearRemovedClasses(array $collectors, array $reflections): void
    {
        $path = base_path('runtime/container/classes.cache');
        $classes = array_keys($reflections);

        $data = [];
        if ($this->filesystem->exists($path)) {
            $data = unserialize($this->filesystem->get($path));
        }

        $this->putCache($path, serialize($classes));

        $removed = array_diff($data, $classes);

        foreach ($removed as $class) {
            foreach ($collectors as $collector) {
                $collector::clear($class);
            }
        }
    }

    protected function putCache(string $path, $data): void
    {
        if (!$this->filesystem->isDirectory($dir = dirname($path))) {
            $this->filesystem->makeDirectory($dir, 0755, true);
        }

        $this->filesystem->put($path, $data);
    }
}
