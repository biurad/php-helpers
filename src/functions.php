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

use ArrayAccess;
use BadFunctionCallException;
use BiuradPHP\DependencyInjection\Interfaces\FactoryInterface;
use BiuradPHP\Events\Interfaces\EventDispatcherInterface;
use BiuradPHP\Loader\Interfaces\DataInterface;
use BiuradPHP\MVC\Framework;
use Closure;
use Exception;
use InvalidArgumentException;
use NumberFormatter;
use RuntimeException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Get the available container instance.
 *
 * @param null|string $abstract
 * @param array       $parameters
 *
 * @return FactoryInterface|object
 */
function app($abstract = null, ...$parameters)
{
    if (null === $abstract) {
        return framework();
    }

    if (empty($parameters)) {
        return framework($abstract);
    }

    return framework()->make($abstract, ...$parameters);
}

/**
 * Get the available container instance.
 *
 * @param null|string $abstract
 * @param array       $parameters
 *
 * @return FactoryInterface|object
 */
function framework($abstract = null, ...$parameters)
{
    $kernel = new Framework();

    if (null === $abstract) {
        return $kernel->{FactoryInterface::class};
    }

    if (empty($parameters)) {
        return $kernel::container()->make($abstract);
    }

    return $kernel::container()->make($abstract, \array_values($parameters));
}

/**
 * Dispatch an event and call the listeners, or
 * Set a new event if event doesnot exist.
 *
 * @param null|object|string $event
 * @param mixed              $args
 *
 * @return FactoryInterface|object
 */
function events($event = null, $args = [])
{
    if (!\class_exists(Framework::class)) {
        throw new RuntimeException('This function can only be used in BiuradPHP Framework');
    }

    if (null === $event) {
        return app(EventDispatcherInterface::class);
    }

    if (app('events')->hasListeners($event)) {
        return app('events')->dispatch($event, $args);
    }

    return app('events')->listen($event, $args);
}

/**
 * Get / set the specified configuration value.
 *
 * If an array is passed as the key, we will assume you want to set an array of values.
 *
 * @param null|array|string $key
 * @param mixed             $default
 *
 * @return DataInterface|mixed
 */
function config($key = null, $default = null)
{
    if (!\class_exists(Framework::class)) {
        throw new RuntimeException('This function can only be used in BiuradPHP Framework');
    }

    if (null === $key) {
        return app(DataInterface::class);
    }

    if (\is_array($key)) {
        app(DataInterface::class)->setWritable();

        return app(DataInterface::class)->offsetSet($key, $default);
    }

    return app(DataInterface::class)->get($key, $default);
}

/**
 * Return the default value of the given value.
 *
 * @param mixed $value
 *
 * @return mixed
 */
function value($value)
{
    return $value instanceof Closure ? $value() : $value;
}

/**
 * Call the given Closure with the given value then return the value.
 *
 * @param mixed         $value
 * @param null|callable $callback
 *
 * @return mixed
 */
function tap($value, $callback = null)
{
    if (null === $callback) {
        return $value;
    }

    $callback($value);

    return $value;
}

/**
 * Allows user to retrieve values from the environment
 * variables that have been set. Especially useful for
 * retrieving values set from the .env file for
 * use in config files.
 *
 * @param string     $key
 * @param null|mixed $default
 *
 * @return mixed
 */
function env($key, $default = null)
{
    $value = $_ENV[$key] ?? \getenv($key) ?? $_SERVER[$key];

    // Not found? Return the default value
    if ($value === false) {
        return $default;
    }

    // Handle any boolean values
    switch (\strtolower($value)) {
        case 'true':
            return true;
        case 'false':
            return false;
        case 'empty':
            return '';
        case 'null':
            return null;
    }

    return $value;
}

/**
 * Interpolate string with given parameters, used by many spiral components.
 *
 * Input: Hello {name}! Good {time}! + ['name' => 'Member', 'time' => 'day']
 * Output: Hello Member! Good Day!
 *
 * @param string $string
 * @param array  $values      Arguments (key => value). Will skip unknown names.
 * @param string $placeholder placeholder prefix, "{" by default
 *
 * @return mixed
 */
function interpolate($string, $values = [], $placeholder = '{|}')
{
    $replaces = [];

    foreach ($values as $key => $value) {
        $value = (\is_array($value) || $value instanceof Closure) ? '' : $value;

        try {
            //Object as string
            $value = \is_object($value) ? (string) $value : $value;
        } catch (Exception $e) {
            $value = '';
        }

        $prefix                                   = \explode('|', $placeholder);
        $replaces[$prefix[0] . $key . $prefix[1]] = $value;
    }

    return \strtr($string, $replaces);
}

/**
 * Get an item from an object using "dot" notation.
 *
 * @param object $object
 * @param string $key
 * @param mixed  $default
 *
 * @return mixed
 */
function object_get($object, $key, $default = null)
{
    if (null === $key || \trim($key) == '') {
        return $object;
    }

    foreach (\explode('.', $key) as $segment) {
        if (!\is_object($object) || !isset($object->{$segment})) {
            return value($default);
        }

        $object = $object->{$segment};
    }

    return $object;
}

/**
 * Set an item from an object using "dot" notation.
 *
 * @param object $object
 * @param string $key
 * @param mixed  $value
 *
 * @return mixed
 */
function object_set(&$object, $key, $value)
{
    if (null === $key || \trim($key) == '') {
        return $object;
    }

    foreach (\explode('.', $key) as $field) {
        if (\is_object($object)) {
            // Handle objects.
            if (!isset($object->{$field})) {
                $object->{$field} = [];
            }
            $object = &$object->{$field};
        }
    }

    $object = $value;
}

/**
 * Fill in data where it's missing.
 *
 * @param mixed        $target
 * @param array|string $key
 * @param mixed        $value
 *
 * @return mixed
 */
function data_fill(&$target, $key, $value)
{
    return data_set($target, $key, $value, false);
}

/**
 * Set an item on an array or object using dot notation.
 *
 * @param mixed        $target
 * @param array|string $key
 * @param mixed        $value
 * @param bool         $overwrite
 *
 * @return mixed
 */
function data_set(&$target, $key, $value, $overwrite = true)
{
    $segments = \is_array($key) ? $key : \explode('.', $key);

    if (($segment = \array_shift($segments)) === '*') {
        if (!\is_array($target) || !$target instanceof ArrayAccess) {
            $target = [];
        }

        if ($segments) {
            foreach ($target as &$inner) {
                data_set($inner, $segments, $value, $overwrite);
            }
        } elseif ($overwrite) {
            foreach ($target as &$inner) {
                $inner = $value;
            }
        }
    } elseif (\is_array($target) || $target instanceof ArrayAccess) {
        if ($segments) {
            if (!\array_key_exists($segment, $target)) {
                $target[$segment] = [];
            }

            data_set($target[$segment], $segments, $value, $overwrite);
        } elseif ($overwrite || !\array_key_exists($segment, $target)) {
            $target[$segment] = $value;
        }
    } elseif (\is_object($target)) {
        if ($segments) {
            if (!isset($target->{$segment})) {
                $target->{$segment} = [];
            }

            data_set($target->{$segment}, $segments, $value, $overwrite);
        } elseif ($overwrite || !isset($target->{$segment})) {
            $target->{$segment} = $value;
        }
    } else {
        $target = [];

        if ($segments) {
            data_set($target[$segment], $segments, $value, $overwrite);
        } elseif ($overwrite) {
            $target[$segment] = $value;
        }
    }

    return $target;
}

/**
 * Remove one or many array/object items from a given array using "dot" notation.
 *
 * @param array|object $array
 * @param array|string $keys
 */
function array_forget(&$array, $keys): void
{
    $keys = (array) $keys;

    if (\count($keys) === 0) {
        return;
    }

    foreach ($keys as $key) {
        // if the exact key exists in the top-level, remove it
        if (\array_key_exists($key, $array)) {
            unset($array[$key]);

            continue;
        }

        $path = \explode('.', $key);
        $var  = \array_pop($path);

        foreach ($path as $field) {
            if (\is_object($array)) {
                // Handle objects.
                if (!isset($array->{$field})) {
                    return;
                }
                $array = &$array->{$field};
            } else {
                // Handle arrays and scalars.
                if (!\is_array($array) || !isset($array[$field])) {
                    return;
                }
                $array = &$array[$field];
            }
        }

        unset($array[$var]);
    }
}

/**
 * Gets a dot-notated key from an array/object, with a default value if it does
 * not exist.
 *
 * @param array|object $array   The search array
 * @param mixed        $key     The dot-notated key or array of keys
 * @param string       $default The default value
 *
 * @throws InvalidArgumentException
 *
 * @return mixed
 */
function array_get($array, $key, $default = null)
{
    if (!\is_array($array) && !$array instanceof ArrayAccess) {
        throw new InvalidArgumentException('First parameter must be an array or ArrayAccess object.');
    }

    if (null === $key) {
        return $array;
    }

    if (\is_array($key)) {
        $return = [];

        foreach ($key as $k) {
            $return[$k] = array_get($array, $k, $default);
        }

        return $return;
    }

    if (\is_object($key)) {
        $key = (string) $key;
    }

    if (\array_key_exists($key, $array)) {
        return $array[$key];
    }

    foreach (\explode('.', $key) as $field) {
        if (\is_object($array) && isset($array->{$field})) {
            $array = $array->{$field};
        } elseif (\is_array($array) && isset($array[$field])) {
            $array = $array[$field];
        } else {
            return value($default);
        }
    }

    return $array;
}

/**
 * Set an array/object item (dot-notated) to the value.
 *
 * @param array|object $array The array to insert it into
 * @param mixed        $key   The dot-notated key to set or array of keys
 * @param mixed        $value The value
 */
function array_set(&$array, $key, $value = null): void
{
    if (null === $key) {
        $array = $value;

        return;
    }

    if (\is_array($key)) {
        foreach ($key as $k => $v) {
            array_set($array, $k, $v);
        }

        return;
    }

    foreach (\explode('.', $key) as $field) {
        if (\is_object($array)) {
            // Handle objects.
            if (!isset($array->{$field})) {
                $array->{$field} = [];
            }
            $array = &$array->{$field};
        } else {
            // Handle arrays and scalars.
            if (!\is_array($array)) {
                $array = [$field => []];
            } elseif (!isset($array[$field])) {
                $array[$field] = [];
            }
            $array = &$array[$field];
        }
    }

    $array = $value;
}

/**
 * Get the class "basename" of the given object / class.
 *
 * @param object|string $class
 *
 * @return string
 */
function class_basename($class)
{
    $class = \is_object($class) ? \get_class($class) : $class;

    return \basename(\str_replace('\\', '/', $class));
}

/**
 * Returns all traits used by a class, its parent classes and trait of their traits.
 *
 * @param object|string $class
 *
 * @return array
 */
function class_uses_recursive($class)
{
    if (\is_object($class)) {
        $class = \get_class($class);
    }

    $results = [];

    foreach (\array_reverse(\class_parents($class)) + [$class => $class] as $class) {
        $results += trait_uses_recursive($class);
    }

    return \array_unique($results);
}

/**
 * Returns all traits used by a trait and its traits.
 *
 * @param string $trait
 *
 * @return array
 */
function trait_uses_recursive($trait)
{
    $traits = \class_uses($trait);

    foreach ($traits as $trait) {
        $traits += trait_uses_recursive($trait);
    }

    return $traits;
}

/**
 * Retry an operation a given number of times.
 *
 * @param int      $times
 * @param callable $callback
 * @param int      $sleep
 *
 * @throws Exception
 *
 * @return mixed
 */
function retry($times, $callback, $sleep = 0)
{
    $times--;

    beginning: try {
        return $callback();
    } catch (Exception $e) {
        if (!$times) {
            throw $e;
        }

        $times--;

        if ($sleep) {
            \usleep($sleep * 1000);
        }

        goto beginning;
    }
}

/**
 * Get the CSRF token value.
 *
 * @param string $token
 *
 * @return string
 */
function csrf_token($token = '_token')
{
    if (!\class_exists(Framework::class) && \session_status() == \PHP_SESSION_ACTIVE) {
        return $_SESSION[$token];
    }

    try {
        return app(CsrfTokenManagerInterface::class)->getToken($token)->getValue();
    } catch (Exception $e) {
        throw new RuntimeException('Application session store not set.');
    }
}

/**
 * A general purpose, locale-aware, number_format method.
 * Used by all of the functions of the number_helper.
 *
 * @param float       $num
 * @param int         $precision
 * @param null|string $locale
 * @param array       $options
 *
 * @return string
 */
function format_number($num, $precision = 1, $locale = null, $options = []): string
{
    if (!\extension_loaded('intl')) {
        throw new RuntimeException('Intl PHP extension seems missing from your server');
    }

    // Locale is either passed in here, negotiated with client, or grabbed from our config file.
    $locale = $locale ?? \locale_get_default();

    // Type can be any of the NumberFormatter options, but provide a default.
    $type = (int) ($options['type'] ?? NumberFormatter::DECIMAL);

    // In order to specify a precision, we'll have to modify
    // the pattern used by NumberFormatter.
    $pattern = '#,##0.' . \str_repeat('#', $precision);

    $formatter = new NumberFormatter($locale, $type);

    // Try to format it per the locale
    if ($type === NumberFormatter::CURRENCY) {
        $output = $formatter->formatCurrency($num, $options['currency']);
    } else {
        $formatter->setPattern($pattern);
        $output = $formatter->format($num);
    }

    // This might lead a trailing period if $precision == 0
    $output = \trim($output, '. ');

    if (\intl_is_failure($formatter->getErrorCode())) {
        throw new BadFunctionCallException($formatter->getErrorMessage());
    }

    // Add on any before/after text.
    if (isset($options['before']) && \is_string($options['before'])) {
        $output = $options['before'] . $output;
    }

    if (isset($options['after']) && \is_string($options['after'])) {
        $output .= $options['after'];
    }

    return $output;
}

/**
 * Convert a string into array.
 *
 * @param string   $string
 * @param string   $delimiter
 * @param null|int $limit
 * @param bool     $ignoreCase
 *
 * @return array
 */
function str_split(string $string, $delimiter, $limit = null, $ignoreCase = false): array
{
    if (1 > $limit = $limit ?? \PHP_INT_MAX) {
        throw new InvalidArgumentException('Split limit must be a positive integer.');
    }

    if ('' === $delimiter) {
        throw new InvalidArgumentException('Split delimiter is empty.');
    }

    if (false === $delimiter) {
        throw new InvalidArgumentException('Split delimiter is not a valid UTF-8 string.');
    }

    $tail    = $string;
    $chunks  = [];
    $indexOf = $ignoreCase ? 'stripos' : 'strpos';

    while (1 < $limit && false !== $i = $indexOf($tail, $delimiter)) {
        $string   = \substr($tail, 0, $i);
        $chunks[] = $string;
        $tail     = \substr($tail, \strlen($string) + \strlen($delimiter));
        --$limit;
    }

    $string   = $tail;
    $chunks[] = $string;

    return $chunks;
}

/**
 * strip_explode($str)
 *
 * Replaces all non-word characters and underscores in $str with a space.
 * Then it explodes that result using the space for a delimiter.
 *
 * @param array|string $str
 *
 * @return array
 */
function strip_explode($str)
{
    $stripped = \preg_replace('/[\W_]+/', ' ', $str);
    $parts    = \explode(' ', \trim($stripped));

    // If it's not already there put the untouched input at the top of the array
    if (!\in_array($str, $parts)) {
        \array_unshift($parts, $str);
    }

    return $parts;
}

/**
 * Detects debug mode by IP addresses or computer names whitelist detection.
 * Can be used to set a debug mode or production mode of a website.
 *
 * @param array|string $list
 * @param string       $cookieName
 *
 * @return bool
 */
function detect_debug_mode($list = null, $cookieName = 'PHPSESSID'): bool
{
    if (null === $cookieName) {
        throw new RuntimeException(
            'Cookie Name cannot be set null, a default cookie name from website is required. eg: PHPSESSID'
        );
    }
    $addr   = $_SERVER['REMOTE_ADDR'] ?? \php_uname('n');
    $secret = \is_string($_COOKIE[$cookieName] ?? null)
        ? $_COOKIE[$cookieName]
        : null;
    $list = \is_string($list) ? \preg_split('#[,\s]+#', $list) : (array) $list;

    if (!isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !isset($_SERVER['HTTP_FORWARDED'])) {
        $list[] = '127.0.0.1';
        $list[] = '::1';
        $list[] = '[::1]'; // workaround for PHP < 7.3.4
        //$list[] = '23.75.345.200';
    }

    return \in_array($addr, $list, true) || \in_array("$secret@$addr", $list, true);
}

/**
 * Detects environment from detect_debug_mode.
 *
 * @param bool $debugMode
 *
 * @return string
 */
function detect_environment($debugMode): string
{
    $environment = 'maintainance';
    $cli         = \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';

    if (\is_bool($debugMode) && true == $debugMode) {
        $environment = 'development';
    } elseif (false == $debugMode && $cli !== true) {
        $environment = 'production';
    } elseif (false !== $cli) {
        $environment = 'development';
    }

    return (string) $environment;
}
