<?php

namespace Laravel\Horizon;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

class SignalMailbox
{
    /**
     * The number of seconds an undelivered signal is kept.
     *
     * @var int
     */
    public $ttl = 300;

    /**
     * The Redis connection instance.
     *
     * @var \Illuminate\Contracts\Redis\Factory
     */
    public $redis;

    /**
     * Create a new signal mailbox instance.
     *
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @return void
     */
    public function __construct(RedisFactory $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Send a signal to the given address.
     *
     * @param  string  $address
     * @param  int  $signal
     * @return void
     */
    public function send($address, $signal)
    {
        $this->connection()->eval(
            "redis.call('rpush', KEYS[1], ARGV[1]) redis.call('expire', KEYS[1], ARGV[2])",
            1, $this->key($address), (string) $signal, $this->ttl
        );
    }

    /**
     * Remove and return every signal waiting at the given address.
     *
     * @param  string  $address
     * @return array<int, int>
     */
    public function drain($address)
    {
        $signals = $this->connection()->eval(
            "local signals = redis.call('lrange', KEYS[1], 0, -1) redis.call('del', KEYS[1]) return signals",
            1, $this->key($address)
        );

        return array_map('intval', is_array($signals) ? $signals : []);
    }

    /**
     * Discard any signals waiting at the given address.
     *
     * @param  string  $address
     * @return void
     */
    public function reset($address)
    {
        $this->connection()->del($this->key($address));
    }

    /**
     * Get the Redis key for the given address.
     *
     * @param  string  $address
     * @return string
     */
    protected function key($address)
    {
        return 'signals:'.$address;
    }

    /**
     * Get the Redis connection instance.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function connection()
    {
        return $this->redis->connection('horizon');
    }
}
