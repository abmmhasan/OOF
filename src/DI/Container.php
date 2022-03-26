<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;

/**
 * Dependency Injector
 *
 * @method static Container registerClass(string $class, array $parameters = []) Register Class with constructor Parameter
 * @method static Container registerMethod(string $class, string $method, array $parameters = []) Register Class and Method (with method parameter)
 * @method static Container registerClosure($closureAlias, Closure $function, array $parameters = []) Register Closure
 * @method Container allowPrivateMethodAccess() Allow access to private methods
 * @method Container registerParamToClass(string $parameterType, array $parameterResource) Set resource for parameter to Class Method resolver
 * @method mixed getInstance($class) Get Class Instance
 * @method mixed callClosure($closureAlias) Call the desired closure
 * @method mixed callMethod($class) Call the desired class (along with the method)
 */
final class Container
{
    private array $functionReference = [];
    private stdClass $stdClass;
    private array $classResource;
    private bool $allowPrivateMethodAccess = false;
    private static Container $instance;
    private array $closureResource;

    /**
     * Class Constructor
     */
    public function __construct()
    {
        $this->stdClass = new stdClass();
    }

    /**
     * @param $method
     * @param $parameter
     * @return mixed
     * @throws Exception
     */
    public static function __callStatic($method, $parameter)
    {
        if (!in_array($method, [
            'registerClass',
            'registerMethod',
            'registerClosure'
        ])) {
            throw new Exception("Invalid method call!");
        }
        self::$instance = self::$instance ?? new self();
        $method = "__$method";
        return (self::$instance)->$method(...$parameter);
    }

    /**
     * @param $method
     * @param $parameters
     * @return mixed
     * @throws Exception
     */
    public function __call($method, $parameters)
    {
        if (!in_array($method, [
            'registerClass',
            'registerMethod',
            'registerClosure',
            'allowPrivateMethodAccess',
            'registerParamToClass',
            'getInstance',
            'callClosure',
            'callMethod'
        ])) {
            throw new Exception("Invalid method call!");
        }
        $method = "__$method";
        return (self::$instance)->$method(...$parameters);
    }

    /**
     * Register Closure
     *
     * @param string $closureAlias
     * @param Closure $function
     * @param array $parameters
     * @return Container
     */
    private function __registerClosure(string $closureAlias, Closure $function, array $parameters = []): Container
    {
        $this->closureResource[$closureAlias] = [
            'on' => $function,
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * Register Class with constructor Parameter
     *
     * @param string $class
     * @param array $parameters
     * @return Container
     */
    private function __registerClass(string $class, array $parameters = []): Container
    {
        $this->classResource[$class]['constructor'] = [
            'on' => '__constructor',
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * Register Class and Method with Parameter (method parameter)
     *
     * @param string $class
     * @param string $method
     * @param array $parameters
     * @return Container
     */
    private function __registerMethod(string $class, string $method, array $parameters = []): Container
    {
        $this->classResource[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param string $parameterType
     * @param array $parameterResource
     * @return Container
     * @throws Exception
     */
    private function __registerParamToClass(string $parameterType, array $parameterResource): Container
    {
        if (!in_array($parameterType, ['constructor', 'method', 'common'])) {
            throw new Exception("$parameterType is invalid!");
        }
        $this->functionReference[$parameterType] = $parameterResource;
        return self::$instance;
    }

    /**
     * Allow access to private methods
     *
     * @return Container
     */
    private function __allowPrivateMethodAccess(): Container
    {
        $this->allowPrivateMethodAccess = true;
        return self::$instance;
    }

    /**
     * Call the desired closure
     *
     * @param string $closureAlias
     * @return mixed
     * @throws ReflectionException
     */
    private function __callClosure(string $closureAlias): mixed
    {
        return $this->closureResource[$closureAlias]['on'](
            ...$this->resolveParameters(
            new ReflectionFunction($this->closureResource[$closureAlias]['on']),
            $this->closureResource[$closureAlias]['params'],
            'constructor'
        )
        );

    }

    /**
     * Call the desired class (along with the method)
     *
     * @param $class
     * @return mixed
     * @throws ReflectionException
     */
    private function __callMethod($class): mixed
    {
        return $this->getResolvedInstance(new ReflectionClass($class))['returned'];
    }

    /**
     * Get Class Instance
     *
     * @param $class
     * @return mixed
     * @throws ReflectionException
     */
    private function __getInstance($class): mixed
    {
        return $this->getResolvedInstance(new ReflectionClass($class))['instance'];
    }

    /**
     * Get resolved Instance & method
     *
     * @param $class
     * @return array
     * @throws ReflectionException
     */
    private function getResolvedInstance($class): array
    {
        $method = $this->classResource[$class->getName()]['method']['on'] ?? $class->getConstant('callOn') ?? false;
        $instance = $this->getClassInstance(
            $class,
            $this->classResource[$class->getName()]['constructor']['params'] ?? []
        );
        $return = null;
        if ($method && $class->hasMethod($method)) {
            $return = $this->invokeMethod(
                $instance,
                $method,
                $this->classResource[$class->getName()]['method']['params'] ?? []
            );
        }
        return [
            'instance' => $instance,
            'returned' => $return,
            'reflection' => $class
        ];
    }

    /**
     * Resolve Function parameter
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $suppliedParameters
     * @param string $type
     * @return array
     * @throws ReflectionException|Exception
     */
    private function resolveParameters(ReflectionFunctionAbstract $reflector, array $suppliedParameters, string $type): array
    {
        $processed = [];
        $instanceCount = 0;
        $values = array_values($suppliedParameters);
        foreach ($reflector->getParameters() as $key => $classParameter) {
            $instance = $this->resolveDependency(
                $reflector->class ?? $reflector->getName(),
                $classParameter, $processed, $type
            );
            $processed[$classParameter->getName()] = match (true) {
                $instance !== $this->stdClass
                => [$instance, $instanceCount++][0],

                !isset($values[$key - $instanceCount]) && $classParameter->isDefaultValueAvailable()
                => $classParameter->getDefaultValue(),

                default => $suppliedParameters[$classParameter->getName()] ??
                    throw new Exception(
                        "Resolution failed: '{$classParameter->getName()}' of $reflector->class::{$reflector->getShortName()}()!"
                    )
            };
        }
        return $processed;
    }

    /**
     * Resolve parameter dependency
     *
     * @param string $callee
     * @param ReflectionParameter $parameter
     * @param $parameters
     * @param $type
     * @return object|null
     * @throws ReflectionException|Exception
     */
    private function resolveDependency(string $callee, ReflectionParameter $parameter, $parameters, $type): ?object
    {
        $class = $this->resolveClass($parameter, $type);
        if ($class) {
            if ($callee === $class->name) {
                throw new Exception("Looped call detected: $callee");
            }
            if (!$this->alreadyExist($class->name, $parameters)) {
                if ($parameter->isDefaultValueAvailable()) {
                    return null;
                }
                return $this->getResolvedInstance($class)['instance'];
            }
        }
        return $this->stdClass;
    }

    /**
     * Get class Instance
     *
     * @param $class
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    private function getClassInstance($class, array $params = []): mixed
    {
        $constructor = $class->getConstructor();
        return $constructor === null ?
            $class->newInstance() :
            $class->newInstanceArgs(
                $this->resolveParameters($constructor, $params, 'constructor')
            );
    }

    /**
     * Get Method return
     *
     * @param $classInstance
     * @param $method
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    private function invokeMethod($classInstance, $method, array $params = []): mixed
    {
        $method = new ReflectionMethod(get_class($classInstance), $method);
        if ($this->allowPrivateMethodAccess) {
            $method->setAccessible(true);
        }
        return $method->invokeArgs(
            $classInstance,
            $this->resolveParameters($method, $params, 'method')
        );
    }

    /**
     * Check & get Reflection instance
     *
     * @param $parameter
     * @param $methodType
     * @return ReflectionClass|null
     * @throws ReflectionException
     */
    private function resolveClass($parameter, $methodType): ?ReflectionClass
    {
        $type = $parameter->getType();
        $name = $parameter->getName();
        return match (true) {
            $type instanceof ReflectionNamedType && !$type->isBuiltin()
            => new ReflectionClass($this->getClassName($parameter, $type->getName())),

            $this->check($methodType, $name)
            => new ReflectionClass($this->functionReference[$methodType][$name]),

            $this->check('common', $name)
            => new ReflectionClass($this->functionReference['common'][$name]),

            default => null
        };
    }

    /**
     * Check if specified class exists
     *
     * @param $type
     * @param $name
     * @return bool
     */
    private function check($type, $name): bool
    {
        return isset($this->functionReference[$type][$name]) &&
            class_exists($this->functionReference[$type][$name], true);
    }

    /**
     * Get the class name for given type
     *
     * @param $parameter
     * @param $name
     * @return string
     */
    private function getClassName($parameter, $name): string
    {
        if (($class = $parameter->getDeclaringClass()) !== null) {
            return match (true) {
                $name === 'self' => $class->getName(),
                $name === 'parent' && ($parent = $class->getParentClass()) => $parent->getName(),
                default => $name
            };
        }
        return $name;
    }

    /**
     * Check if parameter already resolved
     *
     * @param $class
     * @param array $parameters
     * @return bool
     */
    private function alreadyExist($class, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return true;
            }
        }
        return false;
    }
}
