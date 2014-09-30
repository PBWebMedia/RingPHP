<?php
namespace GuzzleHttp\Ring\Client;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\RingFuture;

/**
 * Returns an asynchronous response using curl_multi_* functions.
 *
 * This adapter supports future responses and the "delay" request client
 * option that can be used to delay before sending a request.
 *
 * When using the CurlMultiAdapter, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the "client" key of the request.
 */
class CurlMultiAdapter
{
    /** @var callable */
    private $factory;
    private $selectTimeout;
    private $mh;
    private $active;
    private $handles = [];
    private $delays = [];
    private $maxHandles;

    /**
     * This adapter accepts the following options:
     *
     * - mh: An optional curl_multi resource
     * - handle_factory: An optional callable used to generate curl handle
     *   resources. the callable accepts a request hash and returns an array
     *   of the handle, headers file resource, and the body resource.
     * - select_timeout: Optional timeout (in seconds) to block before timing
     *   out while selecting curl handles. Defaults to 1 second.
     * - max_handles: Optional integer representing the maximum number of
     *   open requests. When this number is reached, the queued futures are
     *   flushed.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->mh = isset($options['mh'])
            ? $options['mh'] : curl_multi_init();
        $this->factory = isset($options['handle_factory'])
            ? $options['handle_factory'] : new CurlFactory();
        $this->selectTimeout = isset($options['select_timeout'])
            ? $options['select_timeout'] : 1;
        $this->maxHandles = isset($options['max_handles'])
            ? $options['max_handles'] : 100;
    }

    public function __destruct()
    {
        // Finish any open connections before terminating the script.
        while ($this->handles) {
            $this->execute();
        }

        if ($this->mh) {
            curl_multi_close($this->mh);
            $this->mh = null;
        }
    }

    public function __invoke(array $request)
    {
        $factory = $this->factory;
        $result = $factory($request);
        $atom = null;
        $realized = false;
        $entry = [
            'request'  => $request,
            'response' => [],
            'handle'   => $result[0],
            'headers'  => &$result[1],
            'body'     => $result[2],
            'atom'     => &$atom,
            'realized' => &$realized
        ];

        $this->addRequest($entry);
        $id = (int) $result[0];

        $future = new RingFuture(
            // Dereference function
            function () use (&$atom) {
                if (!$atom) {
                    $this->execute();
                }
                return $atom;
            },
            // Cancel function that removes the handle and does not finish.
            function () use ($id) {
                return $this->cancel($id);
            },
            $realized
        );

        // Transfer outstanding requests if there are too many open handles.
        if (count($this->handles) >= $this->maxHandles) {
            $this->execute();
        }

        return $future;
    }

    private function addRequest(array &$entry)
    {
        $id = (int) $entry['handle'];
        $this->handles[$id] = $entry;

        // If the request is a delay, then add the reques to the curl multi
        // pool only after the specified delay.
        if (isset($entry['request']['client']['delay'])) {
            $this->delays[$id] = microtime(true) + ($entry['request']['client']['delay'] / 1000);
        } else {
            curl_multi_add_handle($this->mh, $entry['handle']);
            $future = !empty($entry['request']['future'])
                ? $entry['request']['future']
                : false;
            // "lazy" futures are only sent once the pool has many requests.
            if ($future !== 'lazy') {
                do {
                    $mrc = curl_multi_exec($this->mh, $this->active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
                $this->processMessages();
            }
        }
    }

    private function removeProcessed($id)
    {
        if (isset($this->handles[$id])) {
            curl_multi_remove_handle(
                $this->mh,
                $this->handles[$id]['handle']
            );
            curl_close($this->handles[$id]['handle']);
            unset($this->handles[$id], $this->delays[$id]);
        }
    }

    /**
     * Cancels a handle from sending and removes references to it.
     *
     * @param int $id Handle ID to cancel and remove.
     *
     * @return bool True on success, false on failure.
     */
    private function cancel($id)
    {
        // Cannot cancel if it has been processed.
        if (!isset($this->handles[$id])) {
            return false;
        }

        $handle = $this->handles[$id]['handle'];
        unset($this->delays[$id], $this->handles[$id]);
        curl_multi_remove_handle($this->mh, $handle);
        curl_close($handle);

        return true;
    }

    private function execute()
    {
        do {

            if ($this->active &&
                curl_multi_select($this->mh, $this->selectTimeout) === -1
            ) {
                // Perform a usleep if a select returns -1.
                // See: https://bugs.php.net/bug.php?id=61141
                usleep(250);
            }

            // Add any delayed futures if needed.
            if ($this->delays) {
                $this->addDelays();
            }

            do {
                $mrc = curl_multi_exec($this->mh, $this->active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            $this->processMessages();

            // If there are delays but no transfers, then sleep for a bit.
            if (!$this->active && $this->delays) {
                usleep(500);
            }

        } while ($this->active || $this->handles);
    }

    private function addDelays()
    {
        $currentTime = microtime(true);

        foreach ($this->delays as $id => $delay) {
            if ($currentTime >= $delay) {
                unset($this->delays[$id]);
                curl_multi_add_handle(
                    $this->mh,
                    $this->handles[$id]['handle']
                );
            }
        }
    }

    private function processMessages()
    {
        while ($done = curl_multi_info_read($this->mh)) {
            $id = (int) $done['handle'];

            if (!isset($this->handles[$id])) {
                // Probably was cancelled.
                continue;
            }

            $entry =& $this->handles[$id];
            $entry['response']['transfer_stats'] = curl_getinfo($done['handle']);

            if ($done['result'] !== CURLM_OK) {
                $entry['response']['curl']['errno'] = $done['result'];
                if (function_exists('curl_strerror')) {
                    $entry['response']['curl']['error'] = curl_strerror($done['result']);
                }
            }

            // Add the atom value to the entry.
            $entry['atom'] = $this->responseFromEntry($entry);
            $this->removeProcessed($id);
            Core::callThen($entry['request'], $entry['atom']);
            $entry['realized'] = true;
        }
    }

    private function responseFromEntry(array $entry)
    {
        return CurlFactory::createResponse(
            $this,
            $entry['request'],
            $entry['response'],
            $entry['headers'],
            $entry['body']
        );
    }
}
