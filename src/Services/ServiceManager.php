<?php

namespace DreamFactory\Core\Services;

use DreamFactory\Core\Contracts\ServiceTypeInterface;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ServiceRequest;
use InvalidArgumentException;

class ServiceManager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The active service instances.
     *
     * @var array
     */
    protected $services = [];

    /**
     * The custom service resolvers.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * The custom service type information.
     *
     * @var \DreamFactory\Core\Contracts\ServiceTypeInterface[]
     */
    protected $types = [];

    /**
     * Create a new service manager instance.
     *
     * @param  \Illuminate\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a service instance.
     *
     * @param  string $name
     *
     * @return \DreamFactory\Core\Contracts\ServiceInterface
     */
    public function getService($name)
    {
        // If we haven't created this service, we'll create it based on the config provided.
        // todo: Caching the service is causing some strange PHP7 only memory issues.
//        if (!isset($this->services[$name])) {
        $service = $this->makeService($name);

//            if ($this->app->bound('events')) {
//                $connection->setEventDispatcher($this->app['events']);
//            }

        $this->services[$name] = $service;

//        }

        return $this->services[$name];
    }

    public function getServiceIdNameMap($only_active = false)
    {
        if ($only_active) {
            return \Cache::rememberForever('service_mgr:id_name_map_active', function () {
                return Service::whereIsActive(true)->pluck('name', 'id')->toArray();
            });
        }

        return \Cache::rememberForever('service_mgr:id_name_map', function () {
            return Service::pluck('name', 'id')->toArray();
        });
    }

    /**
     * Get a service identifier by its name.
     *
     * @param  string $name
     * @return int|null
     */
    public function getServiceIdByName($name)
    {
        $map = array_flip($this->getServiceIdNameMap());
        if (array_key_exists($name, $map)) {
            return $map[$name];
        }

        return null;
    }

    /**
     * Get a service name by its identifier.
     *
     * @param  int $id
     * @return string
     */
    public function getServiceNameById($id)
    {
        $map = $this->getServiceIdNameMap();
        if (array_key_exists($id, $map)) {
            return $map[$id];
        }

        return null;
    }

    /**
     * Get a service instance by its identifier.
     *
     * @param  int $id
     *
     * @return \DreamFactory\Core\Contracts\ServiceInterface
     */
    public function getServiceById($id)
    {
        $name = $this->getServiceNameById($id);

        return $this->getService($name);
    }

    /**
     * Disconnect from the given service and remove from local cache.
     *
     * @param  string $name
     *
     * @return void
     */
    public function purge($name)
    {
        unset($this->services[$name]);
        \Cache::forget('service_mgr:' . $name);
        \Cache::forget('service_mgr:id_name_map_active');
        \Cache::forget('service_mgr:id_name_map');
    }

    /**
     * Make the service instance.
     *
     * @param  string $name
     *
     * @return \DreamFactory\Core\Contracts\ServiceInterface
     */
    protected function makeService($name)
    {
        $config = $this->getConfig($name);

        // First we will check by the service name to see if an extension has been
        // registered specifically for that service. If it has we will call the
        // Closure and pass it the config allowing it to resolve the service.
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }

        $config = $this->getDbConfig($name);
        $type = $config['type'];

        // Next we will check to see if a type extension has been registered for a service type
        // and will call the factory Closure if so, which allows us to have a more generic
        // resolver for the service types themselves which applies to all services.
        if (isset($this->types[$type])) {
            return $this->types[$type]->make($name, $config);
        }

        throw new InvalidArgumentException("Unsupported service type '$type'.");
    }

    /**
     * Get the configuration for a service.
     *
     * @param  string $name
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function getConfig($name)
    {
        if (empty($name)) {
            throw new InvalidArgumentException("Service 'name' can not be empty.");
        }

        $services = $this->app['config']['df.service'];

        return array_get($services, $name);
    }

    /**
     * Get the configuration for a service.
     *
     * @param  string $name
     * @return array
     * @throws NotFoundException
     */
    protected function getDbConfig($name)
    {
        if (empty($name)) {
            throw new InvalidArgumentException("Service 'name' can not be empty.");
        }

        return \Cache::rememberForever('service_mgr:' . $name, function () use ($name) {
            /** @var Service $service */
            $service = Service::with('service_doc_by_service_id')->whereName($name)->first();
            if (empty($service)) {
                throw new NotFoundException("Could not find a service for $name");
            }

            $service->protectedView = false;

            return $service->toArray();
        });
    }

    /**
     * Register a service type extension resolver.
     *
     * @param  \DreamFactory\Core\Contracts\ServiceTypeInterface|null $type
     *
     * @return void
     */
    public function addType(ServiceTypeInterface $type)
    {
        $this->types[$type->getName()] = $type;
    }

    /**
     * Return the service type info.
     *
     * @param string $name
     *
     * @return \DreamFactory\Core\Contracts\ServiceTypeInterface
     */
    public function getServiceType($name)
    {
        if (isset($this->types[$name])) {
            return $this->types[$name];
        }

        return null;
    }

    /**
     * Return all of the known service types.
     * @param string $group
     *
     * @return \DreamFactory\Core\Contracts\ServiceTypeInterface[]
     */
    public function getServiceTypes($group = null)
    {
        if (!empty($group)) {
            $types = [];
            foreach ($this->types as $type) {
                if (0 === strcasecmp($group, $type->getGroup())) {
                    $types[] = $type;
                }
            }

            return $types;
        }

        return $this->types;
    }

    /**
     * Return all of the created service names.
     *
     * @param bool        $only_active
     * @param string|null $group
     * @return array
     */
    public function getServiceNames($only_active = false, $group = null)
    {
        $results = ($only_active ? Service::whereIsActive(true)->pluck('name') : Service::pluck('name'));

        return $results->all();
    }

    /**
     * Return all of the created service info.
     *
     * @param array|string $fields
     * @param bool         $only_active
     * @param string|null  $group
     * @return array
     */
    public function getServiceList($fields = null, $only_active = false, $group = null)
    {
        $allowed = ['id', 'name', 'label', 'description', 'is_active', 'type'];
        if (empty($fields)) {
            $fields = $allowed;
        }
        $fields = (is_string($fields) ? array_map('trim', explode(',', trim($fields, ','))) : $fields);
        $includeGroup = in_array('group', $fields);
        $includeTypeLabel = in_array('type_label', $fields);
        if (($includeGroup || $includeTypeLabel || !empty($group)) && !in_array('type', $fields)) {
            $fields[] = 'type';
        }
        $fields = array_intersect($fields, $allowed);
        $results = ($only_active ? Service::whereIsActive(true)->get($fields)->toArray() : Service::get($fields)->toArray());
        if ($includeGroup || $includeTypeLabel || !empty($group)) {
            $services = [];
            foreach ($results as $result) {
                if ($typeInfo = $this->getServiceType(array_get($result, 'type'))) {
                    if (!empty($group) && (0 !== strcasecmp($group, $typeInfo->getGroup()))) {
                        continue;
                    }
                    if ($includeGroup) {
                        $result['group'] = $typeInfo->getGroup();
                    }
                    if ($includeTypeLabel) {
                        $result['type_label'] = $typeInfo->getLabel();
                    }
                    $services[] = $result;
                }
            }

            return $services;
        }

        return $results;
    }

    /**
     * @param string      $service
     * @param string      $verb
     * @param string|null $resource
     * @param array       $query
     * @param array       $header
     * @param null        $payload
     * @param string|null $format
     *
     * @return \DreamFactory\Core\Contracts\ServiceResponseInterface
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    public function handleRequest(
        $service,
        $verb = Verbs::GET,
        $resource = null,
        $query = [],
        $header = [],
        $payload = null,
        $format = null
    ) {
        $_FILES = []; // reset so that internal calls can handle other files.
        $request = new ServiceRequest();
        $request->setMethod($verb);
        $request->setParameters($query);
        $request->setHeaders($header);
        if (!empty($payload)) {
            if (is_array($payload)) {
                $request->setContent($payload);
            } elseif (empty($format)) {
                throw new BadRequestException('Payload with undeclared format.');
            } else {
                $request->setContent($payload, $format);
            }
        }

        return $this->getService($service)->handleRequest($request, $resource);
    }
}
