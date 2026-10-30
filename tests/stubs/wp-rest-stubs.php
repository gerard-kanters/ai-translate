<?php
/**
 * Minimal WordPress REST classes for the REST endpoint tests.
 */

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            private $params = [];

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }

            public function set_param($key, $value)
            {
                $this->params[$key] = $value;
            }

            public function get_header($key)
            {
                return null;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            private $data;
            private $status;

            public function __construct($data = null, $status = 200)
            {
                $this->data = $data;
                $this->status = $status;
            }

            public function get_data()
            {
                return $this->data;
            }

            public function get_status()
            {
                return $this->status;
            }
        }
    }
