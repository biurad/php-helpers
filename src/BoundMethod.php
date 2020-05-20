<?php

declare(strict_types=1);

/*
 * This code is under BSD 3-Clause "New" or "Revised" License.
 *
 * PHP version 7 and above required
 *
 * @category  CommonHelpers
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2019 Biurad Group (https://biurad.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * @link      https://www.biurad.com/projects/supportmanager
 * @since     Version 0.1
 */

namespace BiuradPHP\Support;

use Psr\Container\ContainerInterface;
use BiuradPHP\DependencyInjection\Interfaces;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

use function array_values;
use function is_string;
use function is_object;
use function count;
use function array_key_exists;
use function is_array;

use const PREG_UNMATCHED_AS_NULL;

class BoundMethod
{
    public const CALLABLE_PATTERN = '!^([^\:]+)(:|::|@)([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$!';

    /**
     * Call the given `Closure`, `class@method`, `class::method`,
     * `[$class, $method]`, `object` and inject its dependencies.
     *
     * @param  ContainerInterface|null  $container Can be set to null
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public static function call(?ContainerInterface $container = null, $callback, array $parameters = [], $defaultMethod = null)
    {
        if (static::isCallableWithSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }

        if ($container instanceof Interfaces\FactoryInterface) {
            return $container->callMethod($callback, static::getMethodDependencies($container, $callback, $parameters));
        }

        return $callback(...array_values(static::getMethodDependencies($container, $callback, $parameters)));
    }

    /**
     * Call a string reference to a class using `Class@method`, and `class::method` syntax.
     *
     * @param ContainerInterface $container
     * @param string|callable $target
     * @param array $parameters
     * @param string|null $defaultMethod
     * @return mixed
     *
     * @throws ReflectionException
     */
    protected static function callClass(?ContainerInterface $container = null, $target, array $parameters = [], $defaultMethod = null)
    {
        $segments = [$target];
        preg_match(self::CALLABLE_PATTERN, $target, $segments, PREG_UNMATCHED_AS_NULL);

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        if (null !== $container) {
            $class = $container->get($segments[1]);
        } else {
            if ($reflector = new ReflectionClass($segments[1])) {
                if ($reflector->isInstantiable()) {
                    $class = $reflector->newInstance();
                }
            }
        }
        $method = count($segments) === 3 ? $segments[3] : $defaultMethod;

        if (null === $method) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call($container, [$class, $method], $parameters, $defaultMethod);
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param  ContainerInterface|null  $container
     * @param  callable|string  $callback
     * @param  array  $parameters
     *
     * @return array
     *
     * @throws ReflectionException
     */
    protected static function getMethodDependencies(?ContainerInterface $container, $callback, array $parameters = [])
    {
        return static::addDependencyForCallParameter($container, static::getCallReflector($callback), $parameters);
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param  callable|string $callback
     *
     * @return ReflectionFunctionAbstract
     * @throws ReflectionException
     */
    protected static function getCallReflector($callback)
    {
        if (is_object($callback) && !$callback instanceof Closure) {
            $callback = [$callback, '__invoke'];
        }

        return is_array($callback) ? new ReflectionMethod($callback[0], $callback[1])
            : new ReflectionFunction($callback);
    }

    /**
     * Get the dependency for the given call parameter.
     *
     * @param ContainerInterface|null $container
     * @param ReflectionFunctionAbstract $reflection
     * @param array $parameters
     *
     * @return array
     * @throws ReflectionException
     */
    protected static function addDependencyForCallParameter(?ContainerInterface $container, ReflectionFunctionAbstract $reflection, array &$parameters)
    {
        return array_map(
            function (ReflectionParameter $parameter) use ($parameters, $container) {
                if (array_key_exists($parameter->getName(), $parameters)) {
                    return $parameters[$parameter->getName()];
                }

                if ($parameter->getClass() && array_key_exists($parameter->getType()->getName(), $parameters)) {
                    return $parameters[$parameter->getType()->getName()];
                }

                if (($type = $parameter->getType()) && ($class = $parameter->getClass())) {
                    $foundClass = array_filter($parameters, function ($class) use ($type) {
                        return is_a($class, $type->getName());
                    });

                    if (!empty($foundClass)) {
                        return current($foundClass);
                    }

                    if ($container instanceof ContainerInterface) {
                        return $container->get($class->getName());
                    }

                    return $class->newInstance();
                }

                if ($parameter->isDefaultValueAvailable() || $parameter->isOptional()) {
                    return $parameter->getDefaultValue();
                }

                return null;
            },

            $reflection->getParameters()
        );
    }

    /**
     * Determine if the given string is in Class@method, and class::method syntax.
     *
     * @param  string|callable  $callback
     * @return bool
     */
    protected static function isCallableWithSign($callback)
    {
        return is_string($callback) && (preg_match(self::CALLABLE_PATTERN, $callback) || class_exists($callback));
    }
}
