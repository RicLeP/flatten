<?php

namespace Flatten;

use Illuminate\Container\Container;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles the rendering of responses and starting of events.
 */
class Flatten
{
    /**
     * The IoC Container.
     *
     * @var Container
     */
    protected $app;

    /**
     * Setup Flatten and hook it to the application.
     *
     * @param Container $app
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Delegate flushing actions to CacheHandler.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        $class = $this;

        // Go through the classes Flatten decorates
        $decorators = ['cache', 'templating'];
        foreach ($decorators as $decorator) {
            $decorator = $this->app['flatten.'.$decorator];
            if (method_exists($decorator, $method)) {
                $class = $decorator;
                break;
            }
        }

        return call_user_func_array([$class, $method], $arguments);
    }

    ////////////////////////////////////////////////////////////////////
    ///////////////////////// CACHING PROCESS //////////////////////////
    ////////////////////////////////////////////////////////////////////

    /**
     * Starts the caching system.
     *
     * @return bool
     * @codeCoverageIgnore
     */
    public function start()
    {
        return $this->app['flatten.events']->onApplicationBoot();
    }

    /**
     * Stops the caching system.
     *
     * @param \Illuminate\Http\Response|null $response A response to render on end
     *
     * @return bool
     * @codeCoverageIgnore
     */
    public function end($response = null)
    {
        if ($this->app['flatten.context']->shouldRun()) {
            return $this->app['flatten.events']->onApplicationDone($response);
        }
    }

    ////////////////////////////////////////////////////////////////////
    ////////////////////////////// KICKSTART ///////////////////////////
    ////////////////////////////////////////////////////////////////////

    /**
     * Kickstart a raw version of Flatten.
     *
     * @return string|void
     */
    public static function kickstart()
    {
        $filename = static::getKickstartPath(func_get_args());

        // If we have a cache for it, unserialize it and output it
        if ($filename && file_exists($filename)) {
            $contents = file_get_contents($filename);
            exit(unserialize(substr($contents, 10)));
        }
    }

    /**
     * Get the path to the current page for kickstart.
     *
     * @param array $salts
     *
     * @return string|null
     */
    public static function getKickstartPath($salts = [])
    {
        // Get the salts
        $salts = $salts ? implode('-', $salts) : null;

        // Get storage path
        $possible = [
            __DIR__.'/../../../../storage/framework/cache',
            __DIR__.'/../../storage/cache',
            __DIR__.'/../cache',
        ];

        $storage = null;
        foreach ($possible as $path) {
            if (is_dir($path)) {
                $storage = realpath($path);
                break;
            }
        }

        if ($storage && isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
            // Compute cache path
            $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : null;
            $key = $salts.'GET-'.$_SERVER['REQUEST_URI'];
            $key = $query ? $key.'?'.$query : $key;

            // Hash and get path
            $parts = array_slice(str_split($hash = md5($key), 2), 0, 2);
            $filename = $storage.'/'.implode('/', $parts).'/'.$hash;

            return $filename;
        }

        return;
    }

    ////////////////////////////////////////////////////////////////////
    ////////////////////////////// RENDERING ///////////////////////////
    ////////////////////////////////////////////////////////////////////

    /**
     * Create a response to send from content.
     *
     * @param string|null $content
     *
     * @return Response
     */
    public function getResponse($content = null)
    {
        // If no content, get from cache
        if (!$content) {
            $content = $this->app['flatten.cache']->getCache();
        }

        return new Response($content);
    }

    /**
     * Render a content.
     *
     * @param string|null $content A content to render
     *
     * @codeCoverageIgnore
     */
    public function render($content = null)
    {
        $this->getResponse($content)->send();

        exit;
    }

    ////////////////////////////////////////////////////////////////////
    /////////////////////////////// HELPERS ////////////////////////////
    ////////////////////////////////////////////////////////////////////

    /**
     * Get the current page's hash.
     *
     * @param string|null $page
     *
     * @return string A page hash
     */
    public function computeHash($page = null)
    {
        // Get current page URI
        if (!$page) {
            $page = $this->app['flatten.context']->getCurrentUrl();
        }

        // Add additional salts
        $salts = $this->app['config']->get('flatten.saltshaker');

        // Add method and page
        $salts[] = $this->app['request']->getMethod();
        $salts[] = $page;

        return implode('-', $salts);
    }
}
