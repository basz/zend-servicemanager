<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\ServiceManager;

use ReflectionClass;
use Zend\ServiceManager\Exception\CircularReferenceException;

class ServiceManager implements ServiceLocatorInterface
{

    /**@#+
     * Constants
     */
    const SCOPE_PARENT = 'parent';
    const SCOPE_CHILD = 'child';
    /**@#-*/

    /**
     * Lookup for canonicalized names.
     *
     * @var array
     */
    protected $canonicalNames = array();

    /**
     * @var bool
     */
    protected $allowOverride = false;

    /**
     * @var array
     */
    protected $invokableClasses = array();

    /**
     * @var string|callable|\Closure|FactoryInterface[]
     */
    protected $factories = array();

    /**
     * @var AbstractFactoryInterface[]
     */
    protected $abstractFactories = array();

    /**
     * @var array[]
     */
    protected $delegators = array();

    /**
     * @var array
     */
    protected $pendingAbstractFactoryRequests = array();

    /**
     * @var string
     */
    protected $lastAbstractFactoryUsed = null;

    /**
     * @var string
     */
    protected $lastCanonicalNameUsed   = null;

    /**
     * @var array
     */
    protected $shared = array();

    /**
     * Registered services and cached values
     *
     * @var array
     */
    protected $instances = array();

    /**
     * @var array
     */
    protected $aliases = array();

    /**
     * @var array
     */
    protected $initializers = array();

    /**
     * @var ServiceManager[]
     */
    protected $peeringServiceManagers = array();

    /**
     * Whether or not to share by default
     *
     * @var bool
     */
    protected $shareByDefault = true;

    /**
     * @var bool
     */
    protected $retrieveFromPeeringManagerFirst = false;

    /**
     * @var bool Track whether not to throw exceptions during create()
     */
    protected $throwExceptionInCreate = true;

    /**
     * @var array map of characters to be replaced through strtr
     */
    protected $canonicalNamesReplacements = array('-' => '', '_' => '', ' ' => '', '\\' => '', '/' => '');

    /**
     * Constructor
     *
     * @param ConfigInterface $config
     */
    public function __construct(ConfigInterface $config = null)
    {
        if ($config) {
            $config->configureServiceManager($this);
        }
    }

    /**
     * Set allow override
     *
     * @param $allowOverride
     * @return ServiceManager
     */
    public function setAllowOverride($allowOverride)
    {
        $this->allowOverride = (bool) $allowOverride;
        return $this;
    }

    /**
     * Get allow override
     *
     * @return bool
     */
    public function getAllowOverride()
    {
        return $this->allowOverride;
    }

    /**
     * Set flag indicating whether services are shared by default
     *
     * @param  bool $shareByDefault
     * @return ServiceManager
     * @throws Exception\RuntimeException if allowOverride is false
     */
    public function setShareByDefault($shareByDefault)
    {
        if ($this->allowOverride === false) {
            throw new Exception\RuntimeException(sprintf(
                '%s: cannot alter default shared service setting; container is marked immutable (allow_override is false)',
                get_class($this) . '::' . __FUNCTION__
            ));
        }
        $this->shareByDefault = (bool) $shareByDefault;
        return $this;
    }

    /**
     * Are services shared by default?
     *
     * @return bool
     */
    public function shareByDefault()
    {
        return $this->shareByDefault;
    }

    /**
     * Set throw exceptions in create
     *
     * @param  bool $throwExceptionInCreate
     * @return ServiceManager
     */
    public function setThrowExceptionInCreate($throwExceptionInCreate)
    {
        $this->throwExceptionInCreate = $throwExceptionInCreate;
        return $this;
    }

    /**
     * Get throw exceptions in create
     *
     * @return bool
     */
    public function getThrowExceptionInCreate()
    {
        return $this->throwExceptionInCreate;
    }

    /**
     * Set flag indicating whether to pull from peering manager before attempting creation
     *
     * @param  bool $retrieveFromPeeringManagerFirst
     * @return ServiceManager
     */
    public function setRetrieveFromPeeringManagerFirst($retrieveFromPeeringManagerFirst = true)
    {
        $this->retrieveFromPeeringManagerFirst = (bool) $retrieveFromPeeringManagerFirst;
        return $this;
    }

    /**
     * Should we retrieve from the peering manager prior to attempting to create a service?
     *
     * @return bool
     */
    public function retrieveFromPeeringManagerFirst()
    {
        return $this->retrieveFromPeeringManagerFirst;
    }

    /**
     * Set invokable class
     *
     * @param  string  $name
     * @param  string  $invokableClass
     * @param  bool $shared
     * @return ServiceManager
     * @throws Exception\InvalidServiceNameException
     */
    public function setInvokableClass($name, $invokableClass, $shared = null)
    {
        $cName = $this->canonicalizeName($name);

        if ($this->has(array($cName, $name), false)) {
            if ($this->allowOverride === false) {
                throw new Exception\InvalidServiceNameException(sprintf(
                    'A service by the name or alias "%s" already exists and cannot be overridden; please use an alternate name',
                    $name
                ));
            }
            $this->unregisterService($cName);
        }

        if ($shared === null) {
            $shared = $this->shareByDefault;
        }

        $this->invokableClasses[$cName] = $invokableClass;
        $this->shared[$cName]           = (bool) $shared;

        return $this;
    }

    /**
     * Set factory
     *
     * @param  string                           $name
     * @param  string|FactoryInterface|callable $factory
     * @param  bool                             $shared
     * @return ServiceManager
     * @throws Exception\InvalidArgumentException
     * @throws Exception\InvalidServiceNameException
     */
    public function setFactory($name, $factory, $shared = null)
    {
        $cName = $this->canonicalizeName($name);

        if (!($factory instanceof FactoryInterface || is_string($factory) || is_callable($factory))) {
            throw new Exception\InvalidArgumentException(
                'Provided abstract factory must be the class name of an abstract factory or an instance of an AbstractFactoryInterface.'
            );
        }

        if ($this->has(array($cName, $name), false)) {
            if ($this->allowOverride === false) {
                throw new Exception\InvalidServiceNameException(sprintf(
                    'A service by the name or alias "%s" already exists and cannot be overridden, please use an alternate name',
                    $name
                ));
            }
            $this->unregisterService($cName);
        }

        if ($shared === null) {
            $shared = $this->shareByDefault;
        }

        $this->factories[$cName] = $factory;
        $this->shared[$cName]    = (bool) $shared;

        return $this;
    }

    /**
     * Add abstract factory
     *
     * @param  AbstractFactoryInterface|string $factory
     * @param  bool                            $topOfStack
     * @return ServiceManager
     * @throws Exception\InvalidArgumentException if the abstract factory is invalid
     */
    public function addAbstractFactory($factory, $topOfStack = true)
    {
        if (!$factory instanceof AbstractFactoryInterface && is_string($factory)) {
            $factory = new $factory();
        }

        if (!$factory instanceof AbstractFactoryInterface) {
            throw new Exception\InvalidArgumentException(
                'Provided abstract factory must be the class name of an abstract'
                . ' factory or an instance of an AbstractFactoryInterface.'
            );
        }

        if ($topOfStack) {
            array_unshift($this->abstractFactories, $factory);
        } else {
            array_push($this->abstractFactories, $factory);
        }
        return $this;
    }

    /**
     * Sets the given service name as to be handled by a delegator factory
     *
     * @param  string $serviceName          name of the service being the delegate
     * @param  string $delegatorFactoryName name of the service being the delegator factory
     *
     * @return ServiceManager
     */
    public function addDelegator($serviceName, $delegatorFactoryName)
    {
        $cName = $this->canonicalizeName($serviceName);

        if (!isset($this->delegators[$cName])) {
            $this->delegators[$cName] = array();
        }

        $this->delegators[$cName][] = $delegatorFactoryName;

        return $this;
    }

    /**
     * Add initializer
     *
     * @param  callable|InitializerInterface $initializer
     * @param  bool                          $topOfStack
     * @return ServiceManager
     * @throws Exception\InvalidArgumentException
     */
    public function addInitializer($initializer, $topOfStack = true)
    {
        if (!($initializer instanceof InitializerInterface || is_callable($initializer))) {
            if (is_string($initializer)) {
                $initializer = new $initializer;
            }

            if (!($initializer instanceof InitializerInterface || is_callable($initializer))) {
                throw new Exception\InvalidArgumentException('$initializer should be callable.');
            }
        }

        if ($topOfStack) {
            array_unshift($this->initializers, $initializer);
        } else {
            array_push($this->initializers, $initializer);
        }
        return $this;
    }

    /**
     * Register a service with the locator
     *
     * @param  string  $name
     * @param  mixed   $service
     * @return ServiceManager
     * @throws Exception\InvalidServiceNameException
     */
    public function setService($name, $service)
    {
        $cName = $this->canonicalizeName($name);

        if ($this->has($cName, false)) {
            if ($this->allowOverride === false) {
                throw new Exception\InvalidServiceNameException(sprintf(
                    '%s: A service by the name "%s" or alias already exists and cannot be overridden, please use an alternate name.',
                    get_class($this) . '::' . __FUNCTION__,
                    $name
                ));
            }
            $this->unregisterService($cName);
        }

        $this->instances[$cName] = $service;

        return $this;
    }

    /**
     * @param  string $name
     * @param  bool   $isShared
     * @return ServiceManager
     * @throws Exception\ServiceNotFoundException
     */
    public function setShared($name, $isShared)
    {
        $cName = $this->canonicalizeName($name);

        if (
            !isset($this->invokableClasses[$cName])
            && !isset($this->factories[$cName])
            && !$this->canCreateFromAbstractFactory($cName, $name)
        ) {
            throw new Exception\ServiceNotFoundException(sprintf(
                '%s: A service by the name "%s" was not found and could not be marked as shared',
                get_class($this) . '::' . __FUNCTION__,
                $name
            ));
        }

        $this->shared[$cName] = (bool) $isShared;
        return $this;
    }

    /**
     * Resolve the alias for the given canonical name
     *
     * @param  string $cName The canonical name to resolve
     * @return string The resolved canonical name
     */
    protected function resolveAlias($cName)
    {
        $stack = array();

        while ($this->hasAlias($cName)) {
            if (isset($stack[$cName])) {
                throw new CircularReferenceException(sprintf(
                    'Circular alias reference: %s -> %s',
                    implode(' -> ', $stack), $cName
                ));
            }

            $stack[$cName] = $cName;
            $cName = $this->aliases[$cName];
        }

        return $cName;
    }

    /**
     * Retrieve a registered instance
     *
     * @param  string  $name
     * @param  bool    $usePeeringServiceManagers
     * @throws Exception\ServiceNotFoundException
     * @return object|array
     */
    public function get($name, $usePeeringServiceManagers = true)
    {
        // inlined code from ServiceManager::canonicalizeName for performance
        if (isset($this->canonicalNames[$name])) {
            $cName = $this->canonicalNames[$name];
        } else {
            $cName = $this->canonicalizeName($name);
        }

        $isAlias = false;

        if ($this->hasAlias($cName)) {
            $isAlias = true;
            $cName = $this->resolveAlias($cName);
        }

        $instance = null;

        if ($usePeeringServiceManagers && $this->retrieveFromPeeringManagerFirst) {
            $instance = $this->retrieveFromPeeringManager($name);

            if (null !== $instance) {
                return $instance;
            }
        }

        if (isset($this->instances[$cName])) {
            return $this->instances[$cName];
        }

        if (!$instance) {
            if (
                isset($this->invokableClasses[$cName])
                || isset($this->factories[$cName])
                || isset($this->aliases[$cName])
                || $this->canCreateFromAbstractFactory($cName, $name)
            ) {
                $instance = $this->create(array($cName, $name));
            } elseif ($usePeeringServiceManagers && !$this->retrieveFromPeeringManagerFirst) {
                $instance = $this->retrieveFromPeeringManager($name);
            }
        }

        // Still no instance? raise an exception
        if ($instance === null) {
            if ($isAlias) {
                throw new Exception\ServiceNotFoundException(sprintf(
                    'An alias "%s" was requested but no service could be found.',
                    $name
                ));
            }

            throw new Exception\ServiceNotFoundException(sprintf(
                '%s was unable to fetch or create an instance for %s',
                get_class($this) . '::' . __FUNCTION__,
                $name
            ));
        }

        if (
            ($this->shareByDefault && !isset($this->shared[$cName]))
            || (isset($this->shared[$cName]) && $this->shared[$cName] === true)
        ) {
            $this->instances[$cName] = $instance;
        }

        return $instance;
    }

    /**
     * Create an instance of the requested service
     *
     * @param  string|array $name
     *
     * @return bool|object
     */
    public function create($name)
    {
        if (is_array($name)) {
            list($cName, $rName) = $name;
        } else {
            $rName = $name;

            // inlined code from ServiceManager::canonicalizeName for performance
            if (isset($this->canonicalNames[$rName])) {
                $cName = $this->canonicalNames[$name];
            } else {
                $cName = $this->canonicalizeName($name);
            }
        }

        if (isset($this->delegators[$cName])) {
            $serviceManager       = $this;
            $additionalDelegators = count($this->delegators[$cName]) - 1;
            $creationCallback     = function () use ($serviceManager, $rName, $cName) {
                return $serviceManager->doCreate($rName, $cName);
            };

            for ($i = 0; $i < $additionalDelegators; $i += 1) {
                $creationCallback = $this->createDelegatorCallback(
                    $this->delegators[$cName][$i],
                    $rName,
                    $cName,
                    $creationCallback
                );
            }

            /* @var $delegatorFactory DelegatorFactoryInterface */
            $delegatorFactory = $this->get($this->delegators[$cName][$i]);

            return $delegatorFactory->createDelegatorWithName($this, $cName, $rName, $creationCallback);
        }

        return $this->doCreate($rName, $cName);
    }

    /**
     * Creates a callback that uses a delegator to create a service
     *
     * @param string   $delegatorFactoryName name of the delegator factory service
     * @param string   $rName                requested service name
     * @param string   $cName                canonical service name
     * @param callable $creationCallback     callback that is responsible for instantiating the service
     *
     * @return callable
     */
    private function createDelegatorCallback($delegatorFactoryName, $rName, $cName, $creationCallback)
    {
        $serviceManager  = $this;

        return function () use ($serviceManager, $delegatorFactoryName, $rName, $cName, $creationCallback) {
            /* @var $delegatorFactory DelegatorFactoryInterface */
            $delegatorFactory = $serviceManager->get($delegatorFactoryName);

            return $delegatorFactory->createDelegatorWithName($serviceManager, $cName, $rName, $creationCallback);
        };
    }

    /**
     * Actually creates the service
     *
     * @param string $rName real service name
     * @param string $cName canonicalized service name
     *
     * @return bool|mixed|null|object
     * @throws Exception\ServiceNotFoundException
     *
     * @internal this method is internal because of PHP 5.3 compatibility - do not explicitly use it
     */
    public function doCreate($rName, $cName)
    {
        $instance = false;

        if (isset($this->factories[$cName])) {
            $instance = $this->createFromFactory($cName, $rName);
        }

        if ($instance === false && isset($this->invokableClasses[$cName])) {
            $instance = $this->createFromInvokable($cName, $rName);
        }

        if ($instance === false && $this->canCreateFromAbstractFactory($cName, $rName)) {
            $instance = $this->createFromAbstractFactory($cName, $rName);
        }

        if ($instance === false && $this->throwExceptionInCreate) {
            throw new Exception\ServiceNotFoundException(sprintf(
                'No valid instance was found for %s%s',
                $cName,
                ($rName ? '(alias: ' . $rName . ')' : '')
            ));
        }

        foreach ($this->initializers as $initializer) {
            if ($initializer instanceof InitializerInterface) {
                $initializer->initialize($instance, $this);
            } else {
                call_user_func($initializer, $instance, $this);
            }
        }

        return $instance;
    }

    /**
     * Determine if we can create an instance.
     *
     * @param  string|array $name
     * @param  bool         $checkAbstractFactories
     * @return bool
     *
     * @deprecated this method is being deprecated as of zendframework 2.2, and may be removed in future major versions
     */
    public function canCreate($name, $checkAbstractFactories = true)
    {
        if (is_array($name)) {
            list($cName, $rName) = $name;
        } else {
            $rName = $name;
            $cName = $this->canonicalizeName($rName);
        }

        return (
            isset($this->invokableClasses[$cName])
            || isset($this->factories[$cName])
            || isset($this->aliases[$cName])
            || isset($this->instances[$cName])
            || ($checkAbstractFactories && $this->canCreateFromAbstractFactory($cName, $rName))
        );
    }

    /**
     * @param  string|array  $name
     * @param  bool          $checkAbstractFactories
     * @param  bool          $usePeeringServiceManagers
     * @return bool
     */
    public function has($name, $checkAbstractFactories = true, $usePeeringServiceManagers = true)
    {
        if (is_array($name)) {
            list($cName, $rName) = $name;
        } else {
            $rName = $name;

            // inlined code from ServiceManager::canonicalizeName for performance
            if (isset($this->canonicalNames[$rName])) {
                $cName = $this->canonicalNames[$name];
            } else {
                $cName = $this->canonicalizeName($name);
            }
        }

        if (
            isset($this->invokableClasses[$cName])
            || isset($this->factories[$cName])
            || isset($this->aliases[$cName])
            || isset($this->instances[$cName])
            || ($checkAbstractFactories && $this->canCreateFromAbstractFactory($cName, $name))
        ) {
            return true;
        }

        if ($usePeeringServiceManagers) {
            foreach ($this->peeringServiceManagers as $peeringServiceManager) {
                if ($peeringServiceManager->has($rName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if we can create an instance from an abstract factory.
     *
     * @param  string $cName
     * @param  string $rName
     * @return bool
     */
    public function canCreateFromAbstractFactory($cName, $rName)
    {
        // check abstract factories
        foreach ($this->abstractFactories as $abstractFactory) {
            $factoryClass = get_class($abstractFactory);

            if (
                isset($this->pendingAbstractFactoryRequests[$factoryClass])
                && $this->pendingAbstractFactoryRequests[$factoryClass] == $rName
            ) {
                return false;
            }

            $objectHash = spl_object_hash($abstractFactory);

            if ($this->lastAbstractFactoryUsed === $objectHash && $this->lastCanonicalNameUsed === $cName) {
                $this->lastAbstractFactoryUsed = $this->lastCanonicalNameUsed = null;
                return false;
            }

            $this->lastAbstractFactoryUsed = $objectHash;
            $this->lastCanonicalNameUsed   = $cName;

            if ($abstractFactory->canCreateServiceWithName($this, $cName, $rName)) {
                $this->lastAbstractFactoryUsed = $this->lastCanonicalNameUsed = null;
                return true;
            }
        }
        return false;
    }

    /**
     * @param  string $alias
     * @param  string $nameOrAlias
     * @return ServiceManager
     * @throws Exception\ServiceNotFoundException
     * @throws Exception\InvalidServiceNameException
     */
    public function setAlias($alias, $nameOrAlias)
    {
        if (!is_string($alias) || !is_string($nameOrAlias)) {
            throw new Exception\InvalidServiceNameException('Service or alias names must be strings.');
        }

        $cAlias = $this->canonicalizeName($alias);
        $nameOrAlias = $this->canonicalizeName($nameOrAlias);

        if ($alias == '' || $nameOrAlias == '') {
            throw new Exception\InvalidServiceNameException('Invalid service name alias');
        }

        if ($this->allowOverride === false && $this->has(array($cAlias, $alias), false)) {
            throw new Exception\InvalidServiceNameException(sprintf(
                'An alias by the name "%s" or "%s" already exists',
                $cAlias,
                $alias
            ));
        }

        $this->aliases[$cAlias] = $nameOrAlias;
        return $this;
    }

    /**
     * Determine if we have an alias
     *
     * @param  string $alias
     * @return bool
     */
    public function hasAlias($alias)
    {
        return isset($this->aliases[$this->canonicalizeName($alias)]);
    }

    /**
     * Create scoped service manager
     *
     * @param  string $peering
     * @return ServiceManager
     */
    public function createScopedServiceManager($peering = self::SCOPE_PARENT)
    {
        $scopedServiceManager = new ServiceManager();
        if ($peering == self::SCOPE_PARENT) {
            $scopedServiceManager->peeringServiceManagers[] = $this;
        }
        if ($peering == self::SCOPE_CHILD) {
            $this->peeringServiceManagers[] = $scopedServiceManager;
        }
        return $scopedServiceManager;
    }

    /**
     * Add a peering relationship
     *
     * @param  ServiceManager $manager
     * @param  string         $peering
     * @return ServiceManager
     */
    public function addPeeringServiceManager(ServiceManager $manager, $peering = self::SCOPE_PARENT)
    {
        if ($peering == self::SCOPE_PARENT) {
            $this->peeringServiceManagers[] = $manager;
        }
        if ($peering == self::SCOPE_CHILD) {
            $manager->peeringServiceManagers[] = $this;
        }
        return $this;
    }

    /**
     * Canonicalize name
     *
     * @param  string $name
     * @return string
     */
    protected function canonicalizeName($name)
    {
        if (isset($this->canonicalNames[$name])) {
            return $this->canonicalNames[$name];
        }

        // this is just for performance instead of using str_replace
        return $this->canonicalNames[$name] = strtolower(strtr($name, $this->canonicalNamesReplacements));
    }

    /**
     * Create service via callback
     *
     * @param  callable $callable
     * @param  string   $cName
     * @param  string   $rName
     * @throws Exception\ServiceNotCreatedException
     * @throws Exception\ServiceNotFoundException
     * @throws Exception\CircularDependencyFoundException
     * @return object
     */
    protected function createServiceViaCallback($callable, $cName, $rName)
    {
        static $circularDependencyResolver = array();
        $depKey = spl_object_hash($this) . '-' . $cName;

        if (isset($circularDependencyResolver[$depKey])) {
            $circularDependencyResolver = array();
            throw new Exception\CircularDependencyFoundException('Circular dependency for LazyServiceLoader was found for instance ' . $rName);
        }

        try {
            $circularDependencyResolver[$depKey] = true;
            $instance = call_user_func($callable, $this, $cName, $rName);
            unset($circularDependencyResolver[$depKey]);
        } catch (Exception\ServiceNotFoundException $e) {
            unset($circularDependencyResolver[$depKey]);
            throw $e;
        } catch (\Exception $e) {
            unset($circularDependencyResolver[$depKey]);
            throw new Exception\ServiceNotCreatedException(
                sprintf('An exception was raised while creating "%s"; no instance returned', $rName),
                $e->getCode(),
                $e
            );
        }
        if ($instance === null) {
            throw new Exception\ServiceNotCreatedException('The factory was called but did not return an instance.');
        }

        return $instance;
    }

    /**
     * Retrieve a keyed list of all registered services. Handy for debugging!
     *
     * @return array
     */
    public function getRegisteredServices()
    {
        return array(
            'invokableClasses' => array_keys($this->invokableClasses),
            'factories' => array_keys($this->factories),
            'aliases' => array_keys($this->aliases),
            'instances' => array_keys($this->instances),
        );
    }

    /**
     * Retrieve a keyed list of all canonical names. Handy for debugging!
     *
     * @return array
     */
    public function getCanonicalNames()
    {
        return $this->canonicalNames;
    }

    /**
     * Allows to override the canonical names lookup map with predefined
     * values.
     *
     * @param array $canonicalNames
     * @return ServiceManager
     */
    public function setCanonicalNames($canonicalNames)
    {
        $this->canonicalNames = $canonicalNames;

        return $this;
    }

    /**
     * Attempt to retrieve an instance via a peering manager
     *
     * @param  string $name
     * @return mixed
     */
    protected function retrieveFromPeeringManager($name)
    {
        foreach ($this->peeringServiceManagers as $peeringServiceManager) {
            if ($peeringServiceManager->has($name)) {
                return $peeringServiceManager->get($name);
            }
        }

        $name = $this->canonicalizeName($name);

        if ($this->hasAlias($name)) {
            do {
                $name = $this->aliases[$name];
            } while ($this->hasAlias($name));
        }

        foreach ($this->peeringServiceManagers as $peeringServiceManager) {
            if ($peeringServiceManager->has($name)) {
                return $peeringServiceManager->get($name);
            }
        }

        return null;
    }

    /**
     * Attempt to create an instance via an invokable class
     *
     * @param  string $canonicalName
     * @param  string $requestedName
     * @return null|\stdClass
     * @throws Exception\ServiceNotFoundException If resolved class does not exist
     */
    protected function createFromInvokable($canonicalName, $requestedName)
    {
        $invokable = $this->invokableClasses[$canonicalName];
        if (!class_exists($invokable)) {
            throw new Exception\ServiceNotFoundException(sprintf(
                '%s: failed retrieving "%s%s" via invokable class "%s"; class does not exist',
                get_class($this) . '::' . __FUNCTION__,
                $canonicalName,
                ($requestedName ? '(alias: ' . $requestedName . ')' : ''),
                $invokable
            ));
        }
        $instance = new $invokable;
        return $instance;
    }

    /**
     * Attempt to create an instance via a factory
     *
     * @param  string $canonicalName
     * @param  string $requestedName
     * @return mixed
     * @throws Exception\ServiceNotCreatedException If factory is not callable
     */
    protected function createFromFactory($canonicalName, $requestedName)
    {
        $factory = $this->factories[$canonicalName];
        if (is_string($factory) && class_exists($factory, true)) {
            $factory = new $factory;
            $this->factories[$canonicalName] = $factory;
        }
        if ($factory instanceof FactoryInterface) {
            $instance = $this->createServiceViaCallback(array($factory, 'createService'), $canonicalName, $requestedName);
        } elseif (is_callable($factory)) {
            $instance = $this->createServiceViaCallback($factory, $canonicalName, $requestedName);
        } else {
            throw new Exception\ServiceNotCreatedException(sprintf(
                'While attempting to create %s%s an invalid factory was registered for this instance type.',
                $canonicalName,
                ($requestedName ? '(alias: ' . $requestedName . ')' : '')
            ));
        }
        return $instance;
    }

    /**
     * Attempt to create an instance via an abstract factory
     *
     * @param  string $canonicalName
     * @param  string $requestedName
     * @return object|null
     * @throws Exception\ServiceNotCreatedException If abstract factory is not callable
     */
    protected function createFromAbstractFactory($canonicalName, $requestedName)
    {
        foreach ($this->abstractFactories as $index => $abstractFactory) {
            // support factories as strings
            if (is_string($abstractFactory) && class_exists($abstractFactory, true)) {
                $this->abstractFactories[$index] = $abstractFactory = new $abstractFactory;
            } elseif (!$abstractFactory instanceof AbstractFactoryInterface) {
                throw new Exception\ServiceNotCreatedException(sprintf(
                    'While attempting to create %s%s an abstract factory could not produce a valid instance.',
                    $canonicalName,
                    ($requestedName ? '(alias: ' . $requestedName . ')' : '')
                ));
            }
            try {
                if ($abstractFactory->canCreateServiceWithName($this, $canonicalName, $requestedName)) {
                    $this->pendingAbstractFactoryRequests[get_class($abstractFactory)] = $requestedName;
                    $instance = $this->createServiceViaCallback(
                        array($abstractFactory, 'createServiceWithName'),
                        $canonicalName,
                        $requestedName
                    );
                    unset($this->pendingAbstractFactoryRequests[get_class($abstractFactory)]);
                } else {
                    $instance = false;
                }
            } catch (\Exception $e) {
                unset($this->pendingAbstractFactoryRequests[get_class($abstractFactory)]);
                throw new Exception\ServiceNotCreatedException(
                    sprintf(
                        'An abstract factory could not create an instance of %s%s.',
                        $canonicalName,
                        ($requestedName ? '(alias: ' . $requestedName . ')' : '')
                    ),
                    $e->getCode(),
                    $e
                );
            }
            if (is_object($instance)) {
                break;
            }
        }

        return $instance;
    }

    /**
     * Checks if the object has this class as one of its parents
     *
     * @see https://bugs.php.net/bug.php?id=53727
     * @see https://github.com/zendframework/zf2/pull/1807
     *
     * @param string $className
     * @param string $type
     * @return bool
     *
     * @deprecated this method is being deprecated as of zendframework 2.2, and may be removed in future major versions
     */
    protected static function isSubclassOf($className, $type)
    {
        if (is_subclass_of($className, $type)) {
            return true;
        }
        if (version_compare(PHP_VERSION, '5.3.7', '>=')) {
            return false;
        }
        if (!interface_exists($type)) {
            return false;
        }
        $r = new ReflectionClass($className);
        return $r->implementsInterface($type);
    }

    /**
     * Unregister a service
     *
     * Called when $allowOverride is true and we detect that a service being
     * added to the instance already exists. This will remove the duplicate
     * entry, and also any shared flags previously registered.
     *
     * @param  string $canonical
     * @return void
     */
    protected function unregisterService($canonical)
    {
        $types = array('invokableClasses', 'factories', 'aliases');
        foreach ($types as $type) {
            if (isset($this->{$type}[$canonical])) {
                unset($this->{$type}[$canonical]);
                break;
            }
        }

        if (isset($this->instances[$canonical])) {
            unset($this->instances[$canonical]);
        }

        if (isset($this->shared[$canonical])) {
            unset($this->shared[$canonical]);
        }
    }
}
