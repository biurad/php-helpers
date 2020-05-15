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

use function call_user_func_array;
use function method_exists;
use function array_values;
use function is_string;
use function explode;
use function is_object;
use function array_merge;
use function strpos;
use function count;
use function array_key_exists;
use function class_exists;
use function is_array;

class BoundMethod
{
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
        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }

        return static::callBoundMethod($callback, function () use ($container, $callback, $parameters) {
            if (null !== $container) {
                if ($container instanceof Interfaces\FactoryInterface) {
                    return $container->callMethod($callback, static::getMethodDependencies($container, $callback, $parameters));
                } elseif (method_exists($container, 'call')) {
                    return $container->call($callback, static::getMethodDependencies($container, $callback, $parameters));
                }

                return $callback(...array_values(static::getMethodDependencies($container, $callback, $parameters)));
            }

            return call_user_func_array(
                $callback,
                static::getMethodDependencies($container, $callback, $parameters)
            );
        });
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
        $segments = (is_string($target) && strpos($target, '@') !== false) ? explode('@', $target) : $target;

        if (is_string($segments) && strpos($segments, '::') !== false) {
            $segments = explode('::', $segments);
        }

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        if (null !== $container) {
            $class = $container->get($segments[0]);
        } else {
            if ($reflector = new ReflectionClass($segments[0])) {
                if ($reflector->isInstantiable()) {
                    $class = $reflector->newInstance();
                }
            }
        }
        $method = count($segments) === 2 ? $segments[1] : $defaultMethod;

        if (null === $method) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call($container, [$class, $method], $parameters, $defaultMethod);
    }

    /**
     * Call a method that has been bound to the container.
     *
     * @param callable $callback
     * @param mixed $default
     *
     * @return mixed
     */
    protected static function callBoundMethod($callback, $default)
    {
        if (!is_array($callback)) {
            return value($default);
        }

        return value($default);
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param  ContainerInterface|null  $container
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @return array
     *
     * @throws ReflectionException
     */
    protected static function getMethodDependencies(?ContainerInterface $container, $callback, array $parameters = [])
    {
        $dependencies = [];

        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);
        }

        return array_merge($dependencies, $parameters);
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param  callable|string $callback
     * @return ReflectionFunctionAbstract
     *
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
     * @param ReflectionParameter $parameter
     * @param array $parameters
     * @param array $dependencies
     *
     * @return void
     * @throws ReflectionException
     */
    protected static function addDependencyForCallParameter(?ContainerInterface $container, $parameter, array &$parameters, &$dependencies)
    {
        if (array_key_exists($parameter->name, $parameters)) {
            $dependencies[] = $parameters[$parameter->name];

            unset($parameters[$parameter->name]);
        } elseif ($parameter->getClass() && array_key_exists($parameter->getClass()->name, $parameters)) {
            $dependencies[] = $parameters[$parameter->getClass()->name];

            unset($parameters[$parameter->getClass()->name]);
        } elseif ($parameter->getClass()) {
            $foundClass = array_filter($parameters, function ($class) use ($parameter) {
                return is_subclass_of($class, $parameter->getClass()->name);
            });

            if (!empty($foundClass)) {
                $dependencies[] = current($foundClass);
                return;
            }

            if (null !== $container) {
                $dependencies[] = $container->get($parameter->getClass()->name);
                return;
            }

            if ($parameter->getClass()->isInstantiable()) {
                $dependencies[] = $parameter->getClass()->newInstance();
            }
        } elseif ($parameter->isDefaultValueAvailable()) {
            $dependencies[] = $parameter->getDefaultValue();
        }
    }

    /**
     * Determine if the given string is in Class@method, and class::method syntax.
     *
     * @param  string|callable  $callback
     * @return bool
     */
    protected static function isCallableWithAtSign($callback)
    {
        return (is_string($callback) && (strpos($callback, '@') !== false || strpos($callback, '::') !== false)) ||
            (is_array($callback) && count($callback) === 2 && !$callback instanceof Closure &&
                (is_string($callback[0]) && class_exists($callback[0])));
    }
}
