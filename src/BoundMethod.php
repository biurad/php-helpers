<?php

declare(strict_types=1);

/*
 * This file is part of BiuradPHP opensource projects.
 *
 * PHP version 7.1 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2019 Biurad Group (https://biurad.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BiuradPHP\Support;

use Closure;
use InvalidArgumentException;
use Nette\DI\Container;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

class BoundMethod
{
    public const CALLABLE_PATTERN = '!^([^\:]+)(:|::|@)([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$!';

    public const NOT_NULL = 'NULL_DISALLOWED';

    /**
     * Call the given `Closure`, `class@method`, `class::method`,
     * `[$class, $method]`, `object` and inject its dependencies.
     *
     * @param null|ContainerInterface $container     Can be set to null
     * @param callable|string         $callback
     * @param array                   $parameters
     * @param null|string             $defaultMethod
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    public static function call(?ContainerInterface $container = null, $callback, array $parameters = [])
    {
        if (static::isCallableWithSign($callback)) {
            return static::callClass($container, $callback, $parameters);
        }

        $parameters = static::getMethodDependencies($container, $callback, $parameters);

        if ($container instanceof Container) {
            return $container->callMethod($callback, $parameters);
        }

        return $callback(...$parameters);
    }

    /**
     * Get the dependency for the given call parameter.
     *
     * @param null|ContainerInterface    $container
     * @param ReflectionFunctionAbstract $reflection
     * @param array                      $parameters
     *
     * @throws ReflectionException
     *
     * @return array
     */
    public static function addDependencyForCallParameter(
        ?ContainerInterface $container,
        ReflectionFunctionAbstract $reflection,
        array $parameters
    ): array {
        return \array_map(
            function (ReflectionParameter $parameter) use ($parameters, $container) {
                if (\array_key_exists($parameter->getName(), $parameters)) {
                    return $parameters[$parameter->getName()];
                }

                if ($parameter->getClass() && \array_key_exists($parameter->getType()->getName(), $parameters)) {
                    return $parameters[$parameter->getType()->getName()];
                }

                if (($type = $parameter->getType()) && ($class = $parameter->getClass())) {
                    $foundClass = \array_filter($parameters, function ($class) use ($type) {
                        return \is_a($class, $type->getName());
                    });

                    if (!empty($foundClass)) {
                        return \current($foundClass);
                    }

                    if ($container instanceof ContainerInterface) {
                        return $container->get($class->getName());
                    }

                    return $class->newInstance();
                }

                if ($parameter->isDefaultValueAvailable() || $parameter->isOptional()) {
                    // Catch erros for internal functions...
                    try {
                        return $parameter->getDefaultValue();
                    } catch (ReflectionException $e) {
                        // Do nothing, since "null" is returned...
                    }
                }

                return $parameter->allowsNull() ? null : self::NOT_NULL;
            },
            $reflection->getParameters()
        );
    }

    /**
     * Call a string reference to a class using `Class@method`, and `class::method` syntax.
     *
     * @param ContainerInterface $container
     * @param callable|string    $target
     * @param array              $parameters
     * @param null|string        $defaultMethod
     *
     * @throws ReflectionException
     *
     * @return mixed
     */
    protected static function callClass(?ContainerInterface $container = null, $target, array $parameters = [])
    {
        $segments = [$target];
        \preg_match(self::CALLABLE_PATTERN, $target, $segments, \PREG_UNMATCHED_AS_NULL);

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
        $method = \count($segments) === 3 ? $segments[3] : '__invoke';

        if (null === $method) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call($container, [$class, $method], $parameters);
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param null|ContainerInterface $container
     * @param callable|string         $callback
     * @param array                   $parameters
     *
     * @throws ReflectionException
     *
     * @return array
     */
    protected static function getMethodDependencies(?ContainerInterface $container, $callback, array $parameters = [])
    {
        $dependencies = static::addDependencyForCallParameter(
            $container,
            static::getCallReflector($callback),
            $parameters
        );

        return \array_filter($dependencies, function ($parameter) {
            return self::NOT_NULL !== $parameter;
        });
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param callable|string $callback
     *
     * @throws ReflectionException
     *
     * @return ReflectionFunctionAbstract
     */
    protected static function getCallReflector($callback)
    {
        if (\is_object($callback) && !$callback instanceof Closure) {
            $callback = [$callback, '__invoke'];
        }

        return \is_array($callback) ? new ReflectionMethod($callback[0], $callback[1])
            : new ReflectionFunction($callback);
    }

    /**
     * Determine if the given string is in Class@method, and class::method syntax.
     *
     * @param callable|string $callback
     *
     * @return bool
     */
    protected static function isCallableWithSign($callback)
    {
        return \is_string($callback) && (\preg_match(self::CALLABLE_PATTERN, $callback) || \class_exists($callback));
    }
}
