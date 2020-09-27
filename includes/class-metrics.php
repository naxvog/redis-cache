<?php
/**
 * Metrics collection
 *
 * @package Rhubarb\RedisCache
 */

namespace Rhubarb\RedisCache;

defined( '\\ABSPATH' ) || exit;

/**
 * Metrics collection class
 */
class Metrics {

    /**
     * Unique identifier
     *
     * @var string
     */
    public $id;

    /**
     * Cache hits
     *
     * @var int
     */
    public $hits;

    /**
     * Cache misses
     *
     * @var int
     */
    public $misses;

    /**
     * Cache ratio
     *
     * @var float
     */
    public $ratio;

    /**
     * Bytes retrieves
     *
     * @var int
     */
    public $bytes;

    /**
     * Cache needed time
     *
     * @var float
     */
    public $time;

    /**
     * Cache calls
     *
     * @var int
     */
    public $calls;

    /**
     * Metrics timestamp
     *
     * @var int
     */
    public $timestamp;

    /**
     * Constructor
     */
    public function collect() {
        global $wp_object_cache;

        $info = $wp_object_cache->info();

        $this->id = substr( uniqid(), -7 );
        $this->hits = $info->hits;
        $this->misses = $info->misses;
        $this->ratio = $info->ratio;
        $this->bytes = $info->bytes;
        $this->time = round( $info->time, 5 );
        $this->calls = $info->calls;
        $this->timestamp = time();
    }

    /**
     * Initializes the metrics collection
     *
     * @return void
     */
    public static function init() {
        if ( ! self::is_enabled() ) {
            return;
        }

        add_action( 'shutdown', [ self::class, 'record' ] );
        add_action( 'rediscache_discard_metrics', [ self::class, 'discard' ] );
    }

    /**
     * Checks if the collection of metrics is enabled.
     *
     * @return bool
     */
    public static function is_enabled() {
        if ( defined( 'WP_REDIS_DISABLE_METRICS' ) && WP_REDIS_DISABLE_METRICS ) {
            return false;
        }

        return true;
    }

    /**
     * Checks if metrics can be recorded.
     *
     * @return bool
     */
    public static function is_active() {
        global $wp_object_cache;

        return self::is_enabled()
            && Plugin::instance()->get_redis_status()
            && method_exists( $wp_object_cache, 'redis_instance' );
    }

    /**
     * Retrieves metrics max time
     *
     * @return int
     */
    public static function max_time() {
        if ( defined( 'WP_REDIS_METRICS_MAX_TIME' ) ) {
            return intval( WP_REDIS_METRICS_MAX_TIME );
        }

        return HOUR_IN_SECONDS;
    }

    /**
     * Maps the properties to smaller identifiers
     *
     * @return array
     */
    private static function map() {
        return [
            'id' => 'i',
            'hits' => 'h',
            'misses' => 'm',
            'ratio' => 'r',
            'bytes' => 'b',
            'time' => 't',
            'calls' => 'c',
            'timestamp' => 'ts',
        ];
    }

    /**
     * Records metrics and adds them to redis
     *
     * @return void
     */
    public static function record() {
        global $wp_object_cache;

        if ( ! self::is_active() ) {
            return;
        }

        $metrics = new self();
        $metrics->collect();
        $metrics->save();
    }

    /**
     * Retrieves metrics from redis
     *
     * @param int $seconds Number of seconds of the oldest entry to retrieve.
     * @return Metrics[]
     */
    public static function get( $seconds = null ) {
        global $wp_object_cache;

        if ( ! self::is_active() ) {
            return [];
        }

        if ( null === $seconds ) {
            $seconds = self::max_time();
        }

        try {
            $serialied_metrics = $wp_object_cache->redis_instance()->zrangebyscore(
                $wp_object_cache->build_key( 'metrics', 'redis-cache' ),
                time() - $seconds,
                time() - MINUTE_IN_SECONDS,
                [ 'withscores' => true ]
            );
        } catch ( Exception $exception ) {
            error_log( $exception ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return [];
        }

        $metrics = [];
        $prefix = 'O:' . strlen( self::class ) . ':"' . self::class;
        foreach ( $serialied_metrics as $serialized => $timestamp ) {
            // Compatibility: Ignore all non serialized entries as they were used by prior versions.
            if ( 0 !== strpos( $serialized, $prefix ) ) {
                continue;
            }
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            $metrics[] = unserialize( $serialized );
        }

        return $metrics;
    }

    /**
     * Saves the current metrics to redis
     *
     * @return void
     */
    public function save() {
        global $wp_object_cache;

        try {
            $wp_object_cache->redis_instance()->zadd(
                $wp_object_cache->build_key( 'metrics', 'redis-cache' ),
                $this->timestamp,
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
                serialize( $this )
            );
        } catch ( Exception $exception ) {
            error_log( $exception ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Removes recorded metrics after an hour
     *
     * @return void
     */
    public static function discard() {
        global $wp_object_cache;

        if ( ! self::is_active() ) {
            return;
        }

        try {
            $wp_object_cache->redis_instance()->zremrangebyscore(
                $wp_object_cache->build_key( 'metrics', 'redis-cache' ),
                0,
                time() - self::max_time()
            );
        } catch ( Exception $exception ) {
            error_log( $exception ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

}